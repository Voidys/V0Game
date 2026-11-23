<?php

namespace App;

use App\DependencyInjection\Compiler\AvailableLocalesPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use App\Security\TrustedHosts;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/** Application kernel (MicroKernelTrait: config/ and attribute routes); registers the locale list pass. */
class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /** Applies TRUSTED_HOSTS (see "Security checklist" in README.md): requests with another Host header are answered with 400. */
    public function boot(): void
    {
        parent::boot();

        $patterns = TrustedHosts::patterns((string) ($_SERVER['TRUSTED_HOSTS'] ?? $_ENV['TRUSTED_HOSTS'] ?? ''));
        if ($patterns !== []) {
            Request::setTrustedHosts($patterns);
        }
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new AvailableLocalesPass());
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
