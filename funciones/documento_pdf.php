<?php
declare(strict_types=1);
namespace Suave;

/** Generador de documentos PDF escrito en PHP, sin bibliotecas adicionales. */
final class DocumentoPdf
{
    private array $paginas=[''];
    private float $y=42;
    private const ANCHO=595.28;
    private const ALTO=841.89;
    private function agregar(string $orden): void { $this->paginas[array_key_last($this->paginas)].=$orden."\n"; }
    private function nueva(): void { $this->paginas[]=''; $this->y=42; }
    private function espacio(float $alto): void { if ($this->y+$alto>794) $this->nueva(); }
    private static function texto(string $texto): string
    {
        $texto=str_replace(["\r","\n","\t"],[' ',' ',' '],$texto);
        $convertido=iconv('UTF-8','Windows-1252//TRANSLIT',$texto);
        $texto=$convertido===false?'':$convertido;
        return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$texto);
    }
    private function escribir(float $x,float $y,string $texto,float $tamano=10,bool $negrita=false): void
    {
        $this->agregar(sprintf('BT /%s %.2F Tf 0.19 0.13 0.10 rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET',$negrita?'F2':'F1',$tamano,$x,self::ALTO-$y,self::texto($texto)));
    }
    private static function ancho(string $texto,float $tamano): float
    {
        $suma=0;
        foreach (mb_str_split($texto) as $letra) {
            $suma+=str_contains('ilI.,:;!|\' ',$letra)?.25:(str_contains('mwMW@%',$letra)?.90:(preg_match('/[A-ZÁÉÍÓÚÑ]/u',$letra)?.69:.56));
        }
        return $suma*$tamano;
    }
    private static function lineas(string $texto,float $ancho,float $tamano): array
    {
        $resultado=[];
        foreach (explode("\n",str_replace("\r",'',$texto)) as $parrafo) {
            $actual='';
            foreach (preg_split('/\s+/u',$parrafo) as $palabra) {
                if ($actual!=='' && self::ancho($actual.' '.$palabra,$tamano)>$ancho) { $resultado[]=$actual; $actual=''; }
                while (self::ancho($palabra,$tamano)>$ancho && mb_strlen($palabra)>1) {
                    if ($actual!=='') { $resultado[]=$actual; $actual=''; }
                    $corte=max(1,(int)floor($ancho/($tamano*.9)));
                    while ($corte<mb_strlen($palabra) && self::ancho(mb_substr($palabra,0,$corte+1),$tamano)<=$ancho) $corte++;
                    $resultado[]=mb_substr($palabra,0,$corte); $palabra=mb_substr($palabra,$corte);
                }
                $actual.=($actual===''?'':' ').$palabra;
            }
            $resultado[]=$actual;
        }
        return $resultado?:[''];
    }
    public function titulo(string $texto,bool $principal=false): void
    {
        $tamano=$principal?20:13;
        $lineas=self::lineas($texto,523,$tamano); $this->espacio(count($lineas)*($tamano+5)+15);
        $this->y+=8;
        foreach ($lineas as $linea) { $this->escribir(36,$this->y,$linea,$tamano,true); $this->y+=$tamano+5; }
    }
    public function parrafo(string $texto): void
    {
        foreach (self::lineas($texto,523,10) as $linea) { $this->espacio(14); $this->escribir(36,$this->y,$linea); $this->y+=14; }
        $this->y+=6;
    }
    private function fila(array $lineas,array $anchos,int $desde,int $cantidad,bool $encabezado): void
    {
        $alto=$cantidad*12+10; $x=36;
        foreach ($anchos as $i=>$ancho) {
            if ($encabezado) $this->agregar(sprintf('q 0.94 0.90 0.85 rg %.2F %.2F %.2F %.2F re f Q',$x,self::ALTO-$this->y-$alto,$ancho,$alto));
            $this->agregar(sprintf('q 0.82 0.77 0.71 RG 0.4 w %.2F %.2F %.2F %.2F re S Q',$x,self::ALTO-$this->y-$alto,$ancho,$alto));
            foreach (array_slice($lineas[$i],$desde,$cantidad) as $j=>$linea) $this->escribir($x+5,$this->y+13+$j*12,$linea,9,$encabezado);
            $x+=$ancho;
        }
        $this->y+=$alto;
    }
    public function tabla(array $encabezados,array $filas,array $anchos): void
    {
        $cabecera=[];
        foreach ($encabezados as $i=>$valor) $cabecera[]=self::lineas((string)$valor,$anchos[$i]-10,9);
        $altoCabecera=max(array_map('count',$cabecera))*12+10;
        $this->espacio($altoCabecera+35);
        $this->fila($cabecera,$anchos,0,max(array_map('count',$cabecera)),true);
        if (!$filas) $filas=[array_pad(['Sin registros'],count($anchos),'')];
        foreach ($filas as $fila) {
            $lineas=[];
            foreach ($anchos as $i=>$ancho) $lineas[]=self::lineas((string)($fila[$i]??''),$ancho-10,9);
            $cantidad=max(array_map('count',$lineas)); $desde=0;
            while ($desde<$cantidad) {
                $disponibles=(int)floor((794-$this->y-10)/12);
                if ($disponibles<1 || ($desde===0 && $cantidad<15 && $cantidad>$disponibles)) {
                    $this->nueva(); $this->fila($cabecera,$anchos,0,max(array_map('count',$cabecera)),true);
                    $disponibles=(int)floor((794-$this->y-10)/12);
                }
                $parte=min($cantidad-$desde,$disponibles);
                $this->fila($lineas,$anchos,$desde,$parte,false); $desde+=$parte;
            }
        }
        $this->y+=12;
    }
    public function contenido(): string
    {
        $objetos=[]; $objetos[1]='<< /Type /Catalog /Pages 2 0 R >>';
        $objetos[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objetos[4]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $hijos=[]; $cantidad=count($this->paginas);
        foreach ($this->paginas as $i=>$pagina) {
            $numero=5+$i*2; $flujo=$numero+1; $hijos[]=$numero.' 0 R';
            $pagina.="\nBT /F1 8 Tf 0.45 0.39 0.34 rg 1 0 0 1 36 24 Tm (SUAVE - ".self::texto('Página '.($i+1).' de '.$cantidad).") Tj ET\n";
            $objetos[$numero]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$flujo.' 0 R >>';
            $objetos[$flujo]='<< /Length '.strlen($pagina)." >>\nstream\n".$pagina.'endstream';
        }
        $objetos[2]='<< /Type /Pages /Kids ['.implode(' ',$hijos).'] /Count '.$cantidad.' >>'; ksort($objetos);
        $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $posiciones=[0];
        foreach ($objetos as $numero=>$objeto) { $posiciones[$numero]=strlen($pdf); $pdf.=$numero." 0 obj\n".$objeto."\nendobj\n"; }
        $inicio=strlen($pdf); $pdf.='xref'."\n0 ".(count($objetos)+1)."\n0000000000 65535 f \n";
        for ($i=1;$i<=count($objetos);$i++) $pdf.=sprintf("%010d 00000 n \n",$posiciones[$i]);
        return $pdf.'trailer'."\n<< /Size ".(count($objetos)+1)." /Root 1 0 R >>\nstartxref\n".$inicio."\n%%EOF\n";
    }
}
