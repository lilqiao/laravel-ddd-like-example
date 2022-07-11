<?php

namespace App\Domain\Generic\BPM;

use App\Domain\Generic\BPM\Services\HttpClient;
use App\Domain\Generic\BPM\Services\MockClient;
use App\Domain\Generic\BPM\Services\NetworkInterface;
use Exception;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use function config;

class BPMServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register services.
     *
     * @return void
     * @throws Exception
     */
    public function register()
    {
        dump(__METHOD__);

        $networkInterface = config('bpm.network_interface');
        if ($networkInterface == 'http') {
            $concrete = HttpClient::class;
        } elseif ($networkInterface == 'mock') {
            $concrete = MockClient::class;
        } else {
            throw new Exception('配置异常,请选择合适的bpm网络适配器！');
        }

        $this->app->singleton(NetworkInterface::class, $concrete);
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    public function provides()
    {
        return [NetworkInterface::class];
    }
}