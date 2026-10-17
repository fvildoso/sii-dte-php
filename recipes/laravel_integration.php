<?php

declare(strict_types=1);

namespace SiiDte\Recipes;

/**
 * Ejemplo de Service Provider para Laravel 10 / 11 / 12
 *
 * Para registrar en Laravel:
 * 1. Agregar las variables a .env (ver .env.example)
 * 2. Registrar este provider en config/app.php o bootstrap/providers.php
 * 3. Inyectar SiiClient en cualquier Controlador, Job o Comando de Artisan.
 */

use Illuminate\Support\ServiceProvider;
use SiiDte\Folio\FolioManager;
use SiiDte\SiiClient;
use SiiDte\Storage\CertificateStore;
use SiiDte\Storage\DteRepository;

class LaravelSiiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SiiClient::class, function ($app) {
            $certPath = config('services.sii.cert_path', env('SII_CERT_PATH'));
            $certPass = config('services.sii.cert_pass', env('SII_CERT_PASS'));
            $certB64  = config('services.sii.cert_b64', env('SII_CERT_B64'));

            if (!empty($certB64)) {
                $certStore = CertificateStore::fromEnv('SII_CERT_B64', 'SII_CERT_PASS');
            } else {
                $certStore = CertificateStore::fromFile($certPath, $certPass);
            }

            $client = new SiiClient([
                'rut_emisor'        => config('services.sii.rut_emisor', env('SII_RUT_EMISOR')),
                'dv_emisor'         => config('services.sii.dv_emisor', env('SII_DV_EMISOR')),
                'razon_social'      => config('services.sii.razon_social', env('SII_RAZON_SOCIAL')),
                'giro'              => config('services.sii.giro', env('SII_GIRO')),
                'direccion'         => config('services.sii.direccion', env('SII_DIRECCION')),
                'ciudad'            => config('services.sii.ciudad', env('SII_CIUDAD')),
                'codigo_actividad'  => config('services.sii.codigo_actividad', env('SII_CODIGO_ACTIVIDAD', '620200')),
                'numero_resolucion' => (int) config('services.sii.numero_resolucion', env('SII_NUMERO_RESOLUCION', 0)),
                'fecha_resolucion'  => config('services.sii.fecha_resolucion', env('SII_FECHA_RESOLUCION', '2019-10-18')),
                'ambiente'          => config('services.sii.ambiente', env('SII_AMBIENTE', 'certificacion')),
            ], $certStore);

            // Conectar PDO de Laravel (Eloquent/DB)
            if ($app->bound('db')) {
                $pdo = $app->make('db')->connection()->getPdo();
                $client->usarFolioManager(new FolioManager($pdo))
                       ->usarRepositorio(new DteRepository($pdo));
            }

            return $client;
        });
    }
}
