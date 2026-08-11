# Migraciones de Base de Datos

> ⚠️ **Los archivos de esta carpeta son plantillas de referencia, no es llegar y copiar.**
> Cada proyecto tiene su propia configuración de BD, prefijos de tablas, convenciones de
> nombrado y conexiones. Adáptalos antes de ejecutar.

---

## Laravel

**Plantilla:** `database/laravel/2024_01_01_000000_create_sii_dte_tables.php`

**Destino:** `database/migrations/` de tu proyecto Laravel

Cosas a revisar antes de ejecutar:
- Si tu proyecto usa prefijo de tablas (ej: `app_sii_caf`), ajústalo en cada `Schema::create()`
- Si ya tienes una conexión distinta a la default, agrega `->connection('nombre')` en el Migration
- El campo `pdf_bytes` es `binary` — si prefieres guardar el PDF en S3/disco, elimina esa columna

```bash
php artisan migrate
```

---

## Symfony (Doctrine Migrations)

**Plantilla:** `database/symfony/Version20240101000000.php`

**Destino:** `migrations/` de tu proyecto Symfony

Cosas a revisar antes de ejecutar:
- El namespace `DoctrineMigrations` debe coincidir con el configurado en `doctrine_migrations.yaml`
- Si usas PostgreSQL, reemplaza `ENUM(...)` por `VARCHAR` con un CHECK constraint
- Ajusta el charset si tu proyecto no usa `utf8mb4`

```bash
php bin/console doctrine:migrations:migrate
```

---

## Phalcon

**Plantilla:** `database/phalcon/SiiDteMigration.php`

**Destino:** `app/migrations/1.0.0/` (o la carpeta configurada en `phalcon-migrations.php`)

Cosas a revisar antes de ejecutar:
- El nombre de la clase debe coincidir con el nombre del archivo
- La versión del directorio (`1.0.0`) debe ajustarse a la versión de tu proyecto
- Si usas el adaptador PostgreSQL de Phalcon, algunos tipos de columna cambian

```bash
composer require phalcon/migrations
vendor/bin/phalcon-migrations run
```

---

## Phinx

**Plantilla:** `database/phinx/CreateSiiDteTables.php`

**Destino:** `db/migrations/` (o la carpeta configurada en `phinx.php` o `phinx.yml`)

Cosas a revisar antes de ejecutar:
- El nombre del archivo debe llevar timestamp al inicio: `20240101000000_create_sii_dte_tables.php`
- Verifica que el entorno en `phinx.php` apunte a la BD correcta
- Si usas PostgreSQL, el adaptador de Phinx no soporta `enum` nativo — usa `string` con validación en la app

```bash
composer require robmorgan/phinx
vendor/bin/phinx migrate
```

---

## Sin framework (SQL puro)

**Plantilla:** `database/migrations.sql`

```bash
# MySQL / MariaDB:
mysql -u usuario -p mi_base_de_datos < database/migrations.sql

# PostgreSQL:
psql -U usuario -d mi_base_de_datos -f database/migrations.sql
```

---

## Cómo obtener el PDO en cada framework

```php
// Laravel
$pdo = DB::connection()->getPdo();

// Symfony (Doctrine)
$pdo = $entityManager->getConnection()->getNativeConnection();

// Phalcon
$pdo = $di->get('db')->getInternalHandler();

// Phinx (en tu app, no en la migración)
$pdo = new PDO('mysql:host=localhost;dbname=mi_bd', 'user', 'pass');

// Sin framework
$pdo = new PDO('mysql:host=localhost;dbname=mi_bd', 'user', 'pass');
```

```php
// Inyectar en el cliente SII:
$client
    ->usarFolioManager(new FolioManager($pdo))
    ->usarRepositorio(new DteRepository($pdo));
```