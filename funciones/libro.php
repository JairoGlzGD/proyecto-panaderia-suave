<?php
declare(strict_types=1);
namespace Suave;

final class Columnas
{
    public static function stringFromColumnIndex(int $numero): string
    {
        $texto='';
        while ($numero>0) { $numero--; $texto=chr(65+$numero%26).$texto; $numero=intdiv($numero,26); }
        return $texto;
    }
    public static function columnIndexFromString(string $texto): int
    {
        if (!preg_match('/^[A-Z]{1,3}$/D',strtoupper($texto))) throw new ErrorUsuario('Referencia de columna no válida.');
        $numero=0;
        foreach (str_split(strtoupper($texto)) as $letra) $numero=$numero*26+ord($letra)-64;
        return $numero;
    }
    public static function rangeBoundaries(string $rango): array
    {
        if (!preg_match('/^([A-Z]+)([1-9][0-9]*):([A-Z]+)([1-9][0-9]*)$/D',$rango,$p)) throw new ErrorUsuario('Rango de celdas no válido.');
        return [[self::columnIndexFromString($p[1]),(int)$p[2]],[self::columnIndexFromString($p[3]),(int)$p[4]]];
    }
}

final class Celda
{
    public function __construct(private mixed $valor=null,private string $tipo='s') {}
    public function getValue(): mixed { return $this->valor; }
    public function getDataType(): string { return $this->tipo; }
}

final class Hoja
{
    private array $celdas=[];
    private int $filas=1;
    private int $columnas=1;
    public function __construct(private string $nombre,private array $combinadas=[]) {}
    public function agregar(int $columna,int $fila,mixed $valor,string $tipo='s'): void
    {
        if ($columna<1 || $columna>100 || $fila<1 || $fila>10000) throw new ErrorUsuario('Cada hoja admite hasta 10 000 filas y 100 columnas. Prepara una copia con los datos necesarios.');
        $this->celdas[$fila][$columna]=new Celda($valor,$tipo);
        $this->filas=max($this->filas,$fila); $this->columnas=max($this->columnas,$columna);
    }
    public function getCell(array $posicion): Celda { return $this->celdas[(int)$posicion[1]][(int)$posicion[0]]??new Celda(); }
    public function getTitle(): string { return $this->nombre; }
    public function getHighestDataRow(): int { return $this->filas; }
    public function getHighestDataColumn(): string { return Columnas::stringFromColumnIndex($this->columnas); }
    public function getMergeCells(): array { return $this->combinadas; }
}

/** Lectura y escritura propias de XLSX y CSV mediante funciones incluidas en PHP. */
final class Libro
{
    private array $hojas=[];
    private static bool $calendario1904=false;
    public function getAllSheets(): array { return $this->hojas; }
    public function disconnectWorksheets(): void { $this->hojas=[]; }

    private static function xml(string $contenido): \SimpleXMLElement
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i',$contenido)) throw new ErrorUsuario('El archivo contiene una definición XML no admitida.');
        $anterior=libxml_use_internal_errors(true);
        try {
            $xml=simplexml_load_string($contenido,\SimpleXMLElement::class,LIBXML_NONET|LIBXML_COMPACT);
            if ($xml===false) throw new ErrorUsuario('El archivo Excel contiene datos XML dañados.');
            return $xml;
        } finally { libxml_clear_errors(); libxml_use_internal_errors($anterior); }
    }

    private static function temporal(): string
    {
        $ruta=tempnam(defined('SUAVE_PRIVADO')?SUAVE_PRIVADO:sys_get_temp_dir(),'libro_');
        if ($ruta===false) throw new ErrorUsuario('No se pudo preparar el libro. Revisa el espacio disponible.');
        return $ruta;
    }

    public static function leer(string $contenido,string $extension,array $opciones=[]): self
    {
        self::$calendario1904=false;
        if ($extension==='csv') return self::csv($contenido,$opciones);
        if ($extension!=='xlsx') throw new ErrorUsuario('Utiliza Excel .xlsx o CSV. Si tu archivo es .xls, guárdalo como .xlsx desde Excel.');
        $ruta=self::temporal(); $zip=new \ZipArchive();
        try {
            if (file_put_contents($ruta,$contenido)===false || $zip->open($ruta)!==true) throw new ErrorUsuario('El archivo Excel está dañado o protegido.');
            $total=0;
            if ($zip->numFiles>3000) throw new ErrorUsuario('El libro contiene demasiados archivos internos.');
            for ($i=0;$i<$zip->numFiles;$i++) {
                $dato=$zip->statIndex($i); $total+=(int)$dato['size'];
                if ($total>80*1024*1024) throw new ErrorUsuario('El libro supera el tamaño descomprimido permitido.');
            }
            $obtener=static function(string $nombre) use ($zip): string {
                $dato=$zip->getFromName($nombre);
                if ($dato===false) throw new ErrorUsuario('El libro está incompleto: falta una de sus hojas o relaciones.');
                return $dato;
            };
            $principal=self::xml($obtener('xl/workbook.xml'));
            $propiedades=$principal->xpath('//*[local-name()="workbookPr"]');
            self::$calendario1904=in_array((string)($propiedades[0]['date1904']??''),['1','true'],true);
            $relaciones=self::xml($obtener('xl/_rels/workbook.xml.rels'));
            $rutas=[];
            foreach ($relaciones->children() as $relacion) {
                if ((string)$relacion['TargetMode']==='External') continue;
                $objetivo=(string)$relacion['Target'];
                if (str_contains($objetivo,'..') || str_contains($objetivo,'\\') || str_contains($objetivo,':')) continue;
                $rutas[(string)$relacion['Id']]=str_starts_with($objetivo,'/')?ltrim($objetivo,'/'):'xl/'.$objetivo;
            }
            $cadenas=[];
            if ($zip->locateName('xl/sharedStrings.xml')!==false) {
                $compartidas=self::xml($obtener('xl/sharedStrings.xml'));
                foreach ($compartidas->xpath('//*[local-name()="si"]') as $cadena) {
                    $texto=''; foreach ($cadena->xpath('.//*[local-name()="t"]') as $trozo) $texto.=(string)$trozo;
                    $cadenas[]=$texto;
                    if (count($cadenas)>100000) throw new ErrorUsuario('El libro contiene demasiados textos distintos.');
                }
            }
            $libro=new self(); $hojas=$principal->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]');
            if (!$hojas || count($hojas)>20) throw new ErrorUsuario('El libro debe tener entre una y 20 hojas.');
            $cantidad=0;
            foreach ($hojas as $referencia) {
                $atributos=$referencia->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $id=(string)$atributos['id'];
                if (!isset($rutas[$id]) || !str_starts_with($rutas[$id],'xl/worksheets/')) throw new ErrorUsuario('Una hoja utiliza una relación no admitida. Guarda una copia normal de Excel.');
                $xml=self::xml($obtener($rutas[$id]));
                $combinadas=[];
                foreach ($xml->xpath('//*[local-name()="mergeCell"]') as $combinada) {
                    $rango=(string)$combinada['ref'];
                    if (preg_match('/^[A-Z]+[1-9][0-9]*:[A-Z]+[1-9][0-9]*$/D',$rango)) $combinadas[]=$rango;
                }
                $hoja=new Hoja((string)$referencia['name'],$combinadas);
                foreach ($xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]/*[local-name()="c"]') as $celda) {
                    $posicion=(string)$celda['r'];
                    if (!preg_match('/^([A-Z]+)([1-9][0-9]*)$/D',$posicion,$p)) throw new ErrorUsuario('Una celda no tiene una referencia válida.');
                    $valores=$celda->xpath('./*[local-name()="v"]'); $valor=isset($valores[0])?(string)$valores[0]:null;
                    $formulas=$celda->xpath('./*[local-name()="f"]'); $tipo=(string)$celda['t'];
                    if ($formulas) { $valor='='.(string)$formulas[0]; $tipo='f'; }
                    elseif ($tipo==='s') {
                        if (!ctype_digit((string)$valor) || !array_key_exists((int)$valor,$cadenas)) throw new ErrorUsuario('Una celda de texto está dañada.');
                        $valor=$cadenas[(int)$valor];
                    } elseif ($tipo==='inlineStr') {
                        $valor=''; foreach ($celda->xpath('./*[local-name()="is"]//*[local-name()="t"]') as $trozo) $valor.=(string)$trozo;
                    } elseif ($tipo==='e') { $valor=(string)$valor; }
                    elseif ($valor!==null && ($tipo==='' || $tipo==='n') && is_numeric($valor)) {
                        $valor=preg_match('/^-?\d{1,14}$/D',$valor)?(int)$valor:(float)$valor;
                    }
                    if ($valor===null && !$formulas) continue;
                    if (++$cantidad>150000) throw new ErrorUsuario('El libro supera las 150 000 celdas con datos. Divide el archivo por mes.');
                    $hoja->agregar(Columnas::columnIndexFromString($p[1]),(int)$p[2],$valor,$tipo);
                }
                $libro->hojas[]=$hoja;
            }
            return $libro;
        } finally { try { $zip->close(); } catch (\Throwable) {} if (is_file($ruta)) unlink($ruta); }
    }

    private static function csv(string $contenido,array $opciones): self
    {
        $codificacion=$opciones['codificacion']??'UTF-8';
        if ($codificacion==='Windows-1252') $contenido=mb_convert_encoding($contenido,'UTF-8','Windows-1252');
        elseif (!mb_check_encoding($contenido,'UTF-8')) throw new ErrorUsuario('El CSV no utiliza UTF-8. Selecciona Windows-1252 si corresponde al archivo.');
        $contenido=preg_replace('/^\xEF\xBB\xBF/','',$contenido);
        $delimitador=in_array($opciones['delimitador']??';',[';',',',"\t"],true)?($opciones['delimitador']??';'):';';
        $flujo=fopen('php://temp','w+'); fwrite($flujo,$contenido); rewind($flujo);
        $hoja=new Hoja('Registro'); $numero=0; $cantidad=0;
        try {
            while (($fila=fgetcsv($flujo,0,$delimitador,'"',''))!==false) {
                $numero++;
                if ($numero>10000 || count($fila)>100) throw new ErrorUsuario('El CSV admite hasta 10 000 filas y 100 columnas.');
                foreach ($fila as $columna=>$valor) if ($valor!==null && $valor!=='') {
                    if (++$cantidad>150000) throw new ErrorUsuario('El CSV supera las 150 000 celdas con datos.');
                    // A CSV formula is never evaluated and is rejected in mapped fields.
                    $hoja->agregar($columna+1,$numero,$valor,str_starts_with($valor,'=')?'f':'s');
                }
            }
        } finally { fclose($flujo); }
        $libro=new self(); $libro->hojas[]=$hoja; return $libro;
    }

    public static function fecha(float $numero): \DateTimeImmutable
    {
        if (!is_finite($numero) || $numero<1 || $numero>2957000) throw new ErrorUsuario('Fecha numérica de Excel fuera del intervalo admitido.');
        $dias=(int)floor($numero);
        if (self::$calendario1904) return (new \DateTimeImmutable('1904-01-01',new \DateTimeZone('UTC')))->modify('+'.$dias.' days');
        if ($dias===60) throw new ErrorUsuario('La fecha de Excel corresponde al día inexistente 29/02/1900.');
        return (new \DateTimeImmutable($dias<60?'1899-12-31':'1899-12-30',new \DateTimeZone('UTC')))->modify('+'.$dias.' days');
    }

    private static function escapar(mixed $valor): string
    {
        $texto=preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',(string)($valor??''));
        return htmlspecialchars($texto,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }

    /** Each sheet supplies its title, rows, header row and monetary column numbers. */
    public static function escribir(array $hojas): string
    {
        $ruta=self::temporal(); $zip=new \ZipArchive();
        try {
            if ($zip->open($ruta,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true) throw new ErrorUsuario('No se pudo crear el archivo Excel.');
            $tipos='<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
            $principal='<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
            $relaciones='<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            $nombres=[];
            foreach (array_values($hojas) as $indice=>$datos) {
                $numero=$indice+1; $nombre=(string)$datos['nombre']; $clave=mb_strtolower($nombre);
                if (mb_strlen($nombre)>31 || isset($nombres[$clave]) || preg_match('/[\\\\\/\?\*\[\]:]/u',$nombre)) throw new ErrorUsuario('Nombre de hoja no válido.');
                $nombres[$clave]=true;
                $filas=$datos['filas']; $encabezado=$datos['encabezado']??1; $monetarias=$datos['moneda']??[];
                $columnas=max(1,...array_map('count',$filas)); $ultima=Columnas::stringFromColumnIndex($columnas); $cantidad=max(1,count($filas));
                $xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$encabezado.'" topLeftCell="A'.($encabezado+1).'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
                for ($c=1;$c<=$columnas;$c++) $xml.='<col min="'.$c.'" max="'.$c.'" width="'.($c===2?30:23).'" customWidth="1"/>';
                $xml.='</cols><sheetData>';
                foreach ($filas as $r=>$fila) {
                    $numeroFila=$r+1; $xml.='<row r="'.$numeroFila.'">';
                    foreach (array_values($fila) as $c=>$valor) {
                        $esNumero=(is_int($valor)||is_float($valor)) && is_finite((float)$valor);
                        $estilo=$numeroFila===$encabezado?1:(in_array($c+1,$monetarias,true)&&$esNumero&&$numeroFila>$encabezado?2:0);
                        $celda=Columnas::stringFromColumnIndex($c+1).$numeroFila;
                        $xml.='<c r="'.$celda.'" s="'.$estilo.'"'.($esNumero?' t="n"><v>'.self::escapar($valor).'</v>':' t="inlineStr"><is><t xml:space="preserve">'.self::escapar($valor).'</t></is>').'</c>';
                    }
                    $xml.='</row>';
                }
                $xml.='</sheetData><autoFilter ref="A'.$encabezado.':'.$ultima.$cantidad.'"/></worksheet>';
                $zip->addFromString('xl/worksheets/sheet'.$numero.'.xml',$xml);
                $tipos.='<Override PartName="/xl/worksheets/sheet'.$numero.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $principal.='<sheet name="'.self::escapar($nombre).'" sheetId="'.$numero.'" r:id="rId'.$numero.'"/>';
                $relaciones.='<Relationship Id="rId'.$numero.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$numero.'.xml"/>';
            }
            $relaciones.='<Relationship Id="estilos" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
            $zip->addFromString('[Content_Types].xml',$tipos.'</Types>');
            $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="libro" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml',$principal.'</sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels',$relaciones);
            $zip->addFromString('xl/styles.xml','<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF3B281F"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
            if (!$zip->close()) throw new ErrorUsuario('No se pudo terminar el archivo Excel.');
            $contenido=file_get_contents($ruta);
            if ($contenido===false) throw new ErrorUsuario('No se pudo leer el Excel generado.');
            return $contenido;
        } finally { try { $zip->close(); } catch (\Throwable) {} if (is_file($ruta)) unlink($ruta); }
    }
}
