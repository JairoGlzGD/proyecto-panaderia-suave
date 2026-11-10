-- SUAVE: estructura vacía para MySQL 5.7/8 o MariaDB 10.4+.
-- Seleccione una base vacía en phpMyAdmin antes de importar este archivo.
-- No contiene INSERT de datos comerciales ni elimina tablas existentes.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sucursales (
 codigo VARCHAR(32) COLLATE utf8mb4_unicode_ci PRIMARY KEY,
 nombre VARCHAR(160) NOT NULL,
 activo TINYINT(1) NOT NULL DEFAULT 1,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS productos (
 nombre VARCHAR(160) COLLATE utf8mb4_unicode_ci PRIMARY KEY,
 activo TINYINT(1) NOT NULL DEFAULT 1,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS archivos (
 id CHAR(32) PRIMARY KEY,
 nombre VARCHAR(255) NOT NULL,
 ruta VARCHAR(100) NOT NULL UNIQUE,
 mime VARCHAR(100) NOT NULL,
 bytes BIGINT UNSIGNED NOT NULL,
 sha256 CHAR(64) NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entregas (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 referencia VARCHAR(32) NULL UNIQUE,
 version_actual INT UNSIGNED NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entrega_versiones (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 entrega_id BIGINT UNSIGNED NOT NULL,
 numero INT UNSIGNED NOT NULL,
 estado ENUM('Borrador','Registrada') NOT NULL,
 mes CHAR(7) NOT NULL,
 sucursal_codigo VARCHAR(32) NULL,
 sucursal_nombre VARCHAR(160) NULL,
 fecha DATE NULL,
 folio VARCHAR(100) NULL,
 respaldo ENUM('verificado','por_localizar','por_verificar') NULL,
 total_centavos BIGINT UNSIGNED NOT NULL DEFAULT 0,
 datos_json LONGTEXT NOT NULL,
 motivo TEXT NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY version_entrega (entrega_id, numero),
 INDEX consulta_mes (fecha, estado, sucursal_codigo),
 CONSTRAINT fk_version_entrega FOREIGN KEY (entrega_id) REFERENCES entregas(id),
 CONSTRAINT fk_version_sucursal FOREIGN KEY (sucursal_codigo) REFERENCES sucursales(codigo) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entrega_lineas (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 version_id BIGINT UNSIGNED NOT NULL,
 posicion INT UNSIGNED NOT NULL,
 producto_clave VARCHAR(160) NULL,
 producto_nombre VARCHAR(160) NOT NULL,
 cantidad_milesimas BIGINT UNSIGNED NULL,
 precio_centavos BIGINT UNSIGNED NULL,
 importe_centavos BIGINT UNSIGNED NULL,
 UNIQUE KEY linea_version (version_id, posicion),
 CONSTRAINT fk_linea_version FOREIGN KEY (version_id) REFERENCES entrega_versiones(id),
 CONSTRAINT fk_linea_producto FOREIGN KEY (producto_clave) REFERENCES productos(nombre) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entrega_archivos (
 entrega_id BIGINT UNSIGNED NOT NULL,
 archivo_id CHAR(32) NOT NULL,
 version_numero INT UNSIGNED NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (entrega_id, archivo_id),
 CONSTRAINT fk_adjunto_entrega FOREIGN KEY (entrega_id) REFERENCES entregas(id),
 CONSTRAINT fk_adjunto_archivo FOREIGN KEY (archivo_id) REFERENCES archivos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reportes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 mes CHAR(7) NOT NULL,
 numero INT UNSIGNED NOT NULL,
 modalidad ENUM('detalle','totales') NOT NULL,
 archivo_id CHAR(32) NOT NULL,
 sha256 CHAR(64) NOT NULL,
 lectura_json LONGTEXT NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY reporte_mes_version (mes, numero),
 UNIQUE KEY reporte_archivo_mes (mes, sha256),
 CONSTRAINT fk_reporte_archivo FOREIGN KEY (archivo_id) REFERENCES archivos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reporte_filas (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 reporte_id BIGINT UNSIGNED NOT NULL,
 hoja VARCHAR(160) NOT NULL,
 fila INT UNSIGNED NOT NULL,
 sucursal_codigo VARCHAR(32) NOT NULL,
 sucursal_nombre VARCHAR(160) NOT NULL,
 fecha DATE NULL,
 folio VARCHAR(100) NULL,
 importe_centavos BIGINT NOT NULL,
 original_json LONGTEXT NOT NULL,
 UNIQUE KEY fila_fuente (reporte_id, hoja, fila),
 CONSTRAINT fk_fila_reporte FOREIGN KEY (reporte_id) REFERENCES reportes(id),
 CONSTRAINT fk_fila_sucursal FOREIGN KEY (sucursal_codigo) REFERENCES sucursales(codigo) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS periodos (
 mes CHAR(7) PRIMARY KEY,
 estado ENUM('Preliminar','Revisado') NOT NULL DEFAULT 'Preliminar',
 revision INT UNSIGNED NOT NULL DEFAULT 0,
 parametros_json LONGTEXT NULL,
 reporte_id BIGINT UNSIGNED NULL,
 comparacion_id BIGINT UNSIGNED NULL,
 cierre_id BIGINT UNSIGNED NULL,
 actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_periodo_reporte FOREIGN KEY (reporte_id) REFERENCES reportes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comparaciones (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 mes CHAR(7) NOT NULL,
 revision_periodo INT UNSIGNED NOT NULL,
 reporte_id BIGINT UNSIGNED NOT NULL,
 reglas_hash CHAR(64) NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX comparacion_mes (mes, id),
 CONSTRAINT fk_comparacion_reporte FOREIGN KEY (reporte_id) REFERENCES reportes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS casos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 comparacion_id BIGINT UNSIGNED NOT NULL,
 sucursal_codigo VARCHAR(32) NOT NULL,
 tipo VARCHAR(32) NOT NULL,
 estado ENUM('Coincide','Pendiente','Resuelto') NOT NULL,
 versiones_json LONGTEXT NOT NULL,
 filas_json LONGTEXT NOT NULL,
 suave_centavos BIGINT NOT NULL,
 excel_centavos BIGINT NULL,
 validado_centavos BIGINT NULL,
 detalle_json LONGTEXT NOT NULL,
 fusionado_en BIGINT UNSIGNED NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX casos_comparacion (comparacion_id, estado),
 CONSTRAINT fk_caso_comparacion FOREIGN KEY (comparacion_id) REFERENCES comparaciones(id),
 CONSTRAINT fk_caso_fusion FOREIGN KEY (fusionado_en) REFERENCES casos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS caso_revisiones (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 caso_id BIGINT UNSIGNED NOT NULL,
 accion VARCHAR(32) NOT NULL,
 datos_json LONGTEXT NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_revision_caso FOREIGN KEY (caso_id) REFERENCES casos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cierres (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 mes CHAR(7) NOT NULL,
 numero INT UNSIGNED NOT NULL,
 instantanea_json LONGTEXT NOT NULL,
 sha256 CHAR(64) NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY cierre_mes_version (mes, numero)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reaperturas (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 cierre_id BIGINT UNSIGNED NOT NULL,
 motivo TEXT NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_reapertura_cierre FOREIGN KEY (cierre_id) REFERENCES cierres(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS actividad (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 evento VARCHAR(80) NOT NULL,
 tipo VARCHAR(32) NOT NULL,
 referencia VARCHAR(100) NOT NULL,
 registro_id VARCHAR(100) NULL,
 antes_json LONGTEXT NULL,
 despues_json LONGTEXT NULL,
 motivo TEXT NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX actividad_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS operaciones (
 token CHAR(64) PRIMARY KEY,
 resultado_json LONGTEXT NOT NULL,
 creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
