<?php
declare(strict_types=1);
namespace Suave;

final class Catalogos
{
    public function __construct(private BaseDeDatos $db) {}
    public function save(array $data): array
    {
        $type = ($data['tipo'] ?? '') === 'sucursal' ? 'sucursal' : 'producto';
        $table = $type === 'sucursal' ? 'sucursales' : 'productos';
        $key = $type === 'sucursal' ? 'codigo' : 'nombre';
        $original = Numeros::text($data['original'] ?? '', 'Registro original', 160, false);
        $name = Numeros::text($data['nombre'] ?? '', 'Nombre', 160);
        $code = $type === 'sucursal' ? Numeros::text($data['codigo'] ?? '', 'Código', 32) : $name;
        if ($type === 'sucursal' && !preg_match('/^[\p{L}\p{N}_-]+$/uD', $code)) throw new ErrorUsuario('Código: usa letras, números, guion o guion bajo.', 'codigo');
        $existing = $original === '' ? null : $this->db->one("SELECT * FROM $table WHERE $key = ? FOR UPDATE", [$original]);
        if ($original !== '' && !$existing) throw new ErrorUsuario('El registro que intentas editar ya no está disponible. Actualiza la lista.');
        if ($existing && (int)$existing['revision'] !== (int)($data['revision'] ?? 0)) throw new ErrorUsuario('El catálogo cambió mientras lo editabas. Consulta su versión nueva antes de guardar.');
        $collision = $this->db->one("SELECT * FROM $table WHERE $key = ?", [$code]);
        if ($collision && (!$existing || mb_strtolower($collision[$key]) !== mb_strtolower($existing[$key]))) throw new ErrorUsuario('Ya existe ' . ($type === 'producto' ? 'un producto con ese nombre' : 'una sucursal con ese código') . '. Edita o vuelve a usar el registro existente.');
        $active = !empty($data['activo']) ? 1 : 0;
        if (!$existing) {
            if ($type === 'sucursal') $this->db->run('INSERT INTO sucursales(codigo,nombre) VALUES (?,?)', [$code,$name]);
            else $this->db->run('INSERT INTO productos(nombre) VALUES (?)', [$name]);
            $active = 1;
        } else {
            if ($type === 'sucursal' && $code !== $existing['codigo']) throw new ErrorUsuario('El código de una sucursal guardada se conserva para no romper la relación con las fuentes. Puedes modificar su nombre.');
            if ($existing['nombre'] === $name && (int)$existing['activo'] === $active) return ['p'=>'catalogos','mensaje'=>'El catálogo ya tenía esos valores.'];
            if ($type === 'sucursal') $this->db->run('UPDATE sucursales SET nombre=?,activo=?,revision=revision+1 WHERE codigo=?', [$name,$active,$original]);
            else $this->db->run('UPDATE productos SET nombre=?,activo=?,revision=revision+1 WHERE nombre=?', [$name,$active,$original]);
        }
        $after = $this->db->one("SELECT * FROM $table WHERE $key = ?", [$code]);
        $this->db->audit($existing ? 'Catálogo actualizado' : 'Alta de catálogo', $type, $code, $code, $existing, $after, $existing ? 'Edición de nombre o disponibilidad.' : 'Registro inicial.');
        return ['p'=>'catalogos','mensaje'=>ucfirst($type) . ' guardad' . ($type === 'sucursal' ? 'a' : 'o') . '.'];
    }
}
