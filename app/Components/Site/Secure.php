<?php

namespace App\Components\Site;

class Secure
{
    /**
     * @var \App\Components\Brew
     */
    private $brew;

    /**
     * @var \App\Components\CommandLine
     */
    private $cli;

    /**
     * @var \App\Components\Files
     */
    private $files;

    /**
     * @var \App\Components\Stubs
     */
    private $stubs;

    /**
     * @param \App\Components\Brew $brew
     * @param \App\Components\CommandLine $cli
     * @param \App\Components\Files $files
     * @param \App\Components\Stubs $stubs
     */
    public function __construct(
        \App\Components\Brew $brew,
        \App\Components\CommandLine $cli,
        \App\Components\Files $files,
        \App\Components\Stubs $stubs
    ) {
        $this->brew = $brew;
        $this->cli = $cli;
        $this->files = $files;
        $this->stubs = $stubs;
    }

    /**
     * @param string $domain
     * @return bool
     */
    public function canSecure(string $domain): bool
    {
        return $this->canGenerate($domain) || $this->hasPredefined($domain);
    }

    /**
     * @param string $domain
     * @return bool
     */
    public function canGenerate(string $domain): bool
    {
        $securableDomain = config('env.secure.securable_domain');
        return substr($domain, -strlen($securableDomain)) === $securableDomain;
    }

    /**
     * @param string $domain
     * @return bool
     */
    public function hasPredefined(string $domain): bool
    {
        $securedDomainsData = config('env.secure.secured_domains');
        $domain = $this->getPredefinedDomain($domain);
        return !empty($securedDomainsData[$domain]['crt']) && !empty($securedDomainsData[$domain]['key']);
    }

    /**
     * @param string $domain
     * @return null|string
     */
    private function getPredefinedDomain(string $domain): ?string
    {
        $securedDomainsData = config('env.secure.secured_domains');
        return isset($securedDomainsData[$domain]) ? $domain : $this->getWildcardPredefinedDomain($domain);
    }

    /**
     * @param string $domain
     * @return null|string
     */
    private function getWildcardPredefinedDomain(string $domain): ?string
    {
        $securedDomainsData = config('env.secure.secured_domains');
        $securedDomains = array_filter(array_keys($securedDomainsData), function($value) use ($domain) {
            if (strpos($value, '*.') !== 0) {
                return false;
            }

            $value = str_replace('*.', '.', $value);
            return substr($domain, -strlen($value)) === $value;
        });
        return !empty($securedDomains) ? array_shift($securedDomains) : null;
    }

    /**
     * @param string $domain
     * @param string[] $aliases
     * @return void
     */
    public function generate(string $domain, array $aliases = []): void
    {
        if (!$this->canGenerate($domain)) {
            throw new \RuntimeException("The $domain cannot be secured");
        }

        $this->delete($domain);

        $this->files->ensureDirExists(config('env.secure.certificates_path'));

        $this->buildCertificateConf($domain, $aliases);
        $this->createPrivateKey($domain);
        $this->createSigningRequest($domain);
        $this->generateCertificates($domain);
        $this->trustCertificate($domain);
    }

    /**
     * @param string $domain
     * @return void
     */
    public function delete(string $domain): void
    {
        if (!$this->canGenerate($domain)) {
            return;
        }

        $this->files->delete(
            [
                $this->getFilePath($domain, 'crt'),
                $this->getFilePath($domain, 'key'),
                $this->getFilePath($domain, 'conf'),
                $this->getFilePath($domain, 'csr')
            ]
        );

        $this->cli->runQuietly(sprintf('sudo security delete-certificate -c "%s" -t', $domain));
        $this->cli->runQuietly(sprintf('sudo security delete-certificate -c "%s"', $domain));
    }

    /**
     * @param string $domain
     * @param string[] $aliases
     * @return void
     */
    private function buildCertificateConf(string $domain, array $aliases = []): void
    {
        $domains = empty($aliases) ? [$domain] : array_merge([$domain], $aliases);

        $dnsNames = '';
        $count = 0;
        foreach ($domains as $name) {
            $count++;
            $dnsNames .= 'DNS.' . $count .  ' = ' . $name . PHP_EOL;
        }

        $config = $this->stubs->get('openssl.conf', ['DNS_NAMES' => $dnsNames]);
        $this->files->put($this->getFilePath($domain, 'conf'), $config);
    }

    /**
     * @param string $domain
     * @return void
     */
    private function createPrivateKey(string $domain): void
    {
        $command = sprintf('openssl genrsa -out %s 2048', $this->getFilePath($domain, 'key'));
        $this->cli->run($command);
    }

    /**
     * @param string $domain
     * @return void
     */
    private function createSigningRequest(string $domain): void
    {
        $command = sprintf(
            'openssl req -new -key %s -out %s -subj "/C=/ST=/O=/localityName=/commonName=*.%s/organizationalUnitName=/emailAddress=/" -config %s -passin pass:',
            $this->getFilePath($domain, 'key'),
            $this->getFilePath($domain, 'csr'),
            $domain,
            $this->getFilePath($domain, 'conf')
        );
        $this->cli->run($command);
    }

    /**
     * @param string $domain
     * @return void
     */
    private function generateCertificates(string $domain): void
    {
        $command = sprintf(
            'openssl x509 -req -days 365 -in %s -signkey %s -out %s -extensions v3_req -extfile %s',
            $this->getFilePath($domain, 'csr'),
            $this->getFilePath($domain, 'key'),
            $this->getFilePath($domain, 'crt'),
            $this->getFilePath($domain, 'conf')
        );
        $this->cli->run($command);
    }

    /**
     * @param string $domain
     * @return void
     */
    private function trustCertificate(string $domain): void
    {
        $command = sprintf(
            'sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain %s',
            $this->getFilePath($domain, 'crt')
        );
        $this->cli->run($command);
    }

    /**
     * @param string $domain
     * @param string $fileType
     * @return string
     */
    public function getFilePath(string $domain, string $fileType): string
    {
        if ($this->canGenerate($domain)) {
            return config('env.secure.certificates_path') . DIRECTORY_SEPARATOR . $domain . '.' . $fileType;
        }

        if ($this->hasPredefined($domain) && in_array($fileType, ['crt', 'key'])) {
            return config('env.secure.secured_domains')[$this->getPredefinedDomain($domain)][$fileType];
        }

        throw new \RuntimeException('Path cannot be built.');
    }
}
