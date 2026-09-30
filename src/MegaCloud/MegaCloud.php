<?php

namespace Nave\IssSatellite\MegaCloud;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Nave\IssSatellite\MegaCloud\Traits\RealEstateDevelopments;

/**
 * Mega Cloud
 *
 * Documentação: https://dev.mega.com.br/apis/
 */
class MegaCloud
{
    use RealEstateDevelopments;

    private array $config = [];

    public function setCredentials(array $credentials): self
    {
        $this->config = [
            'host' => $credentials['MEGA_CLOUD_HOST'],
            'prefix' => $credentials['MEGA_CLOUD_PREFIX'],
            'username' => $credentials['MEGA_CLOUD_USER'],
            'password' => $credentials['MEGA_CLOUD_PASSWORD'],
            'tenant' => $credentials['MEGA_CLOUD_TENANT'] ?? null,
            'cache_key' => "mega_cloud_{$credentials['MEGA_CLOUD_USER']}",
        ];

        return $this;
    }

    private function prepareRequest(): PendingRequest
    {
        $connectTimeout = Config::get('iss-satellite.mega_cloud.connect_timeout');
        $timeout = Config::get('iss-satellite.mega_cloud.timeout');

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        // Necessário apenas para MegaCloud
        if ($this->config['tenant']) {
            $headers['tenantId'] = $this->config['tenant'];
        }

        return Http::baseUrl("{$this->config['host']}{$this->config['prefix']}")
            ->withHeaders($headers)
            ->connectTimeout($connectTimeout)
            ->timeout($timeout)
            ->retry(3, 500)
            ->throw();
    }

    private function getToken(): ?string
    {
        $cacheKey = $this->config['cache_key'];

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $request = $this->prepareRequest()->post('/Auth/SignIn', [
            'username' => $this->config['username'],
            'password' => $this->config['password'],
        ])
            ->object();

        if (! property_exists($request, 'accessToken')) {
            return null;
        }

        return Cache::remember($cacheKey, now()->addMinutes(90), function () use ($request) {
            return $request->accessToken;
        });
    }

    public function get(string $url, array $query = []): Response
    {
        return $this->prepareRequest()
            ->withToken($this->getToken())
            ->get($url, $query);
    }

    public function post(string $url, array $data = []): Response
    {
        return $this->prepareRequest()
            ->withToken($this->getToken())
            ->post($url, $data);
    }

    public function put(string $url, array $data = []): Response
    {
        return $this->prepareRequest()
            ->withToken($this->getToken())
            ->put($url, $data);
    }

    public function patch(string $url, array $data = []): Response
    {
        return $this->prepareRequest()
            ->withToken($this->getToken())
            ->patch($url, $data);
    }

    public function delete(string $url, array $data = []): Response
    {
        return $this->prepareRequest()
            ->withToken($this->getToken())
            ->delete($url, $data);
    }

    /**
     * Pega o CNPJ da filial
     *
     * @return SupportCollection
     * @throws RequestException|ConnectionException
     */
    public function getParametro(): SupportCollection
    {
        return $this->prepareRequest()
            ->withToken($this->getToken())
            ->get('/global/MegaParametro')
            ->collect('cnpj');
    }

    /**
     * Retorna o cadastro do cliente no mega cloud
     *
     * @param string $cnpjFilial
     * @param string $cnpjCpfCliente
     *
     * @return SupportCollection
     * @throws RequestException|ConnectionException
     */
    public function getAgenteCliente(string $cnpjFilial, string $cnpjCpfCliente): SupportCollection
    {
        return $this->prepareRequest()
            ->withToken($this->getToken())
            ->get("/globalagente/AgenteCliente/cnpjcpf/$cnpjFilial/$cnpjCpfCliente")
            ->collect();
    }

    /**
     * Integra o cliente ao mega cloud
     *
     * @param array $data
     *
     * @return Response
     * @throws RequestException|ConnectionException
     */
    public function createAgenteCliente(array $data): Response
    {
        return $this->prepareRequest()
            ->withToken($this->getToken())
            ->post('/globalagente/AgenteCliente', $data);
    }

    /**
     * Gera proposta no mega cloud
     *
     * @param array $data
     *
     * @return Response
     * @throws RequestException|ConnectionException
     */
    public function geraProposta(array $data): Response
    {
        return $this->prepareRequest()
            ->withToken($this->getToken())
            ->post('/Carteira/GeraProposta', $data);
    }

    /**
     * Pega proposta por empreendimento
     *
     * @param int $idEmpreendimento
     * @param int $idUnidade
     * @param string $cpfCnpj
     * @return array
     *
     * @throws RequestException|ConnectionException
     */
    public function getProposta(int $idEmpreendimento, int $idUnidade, string $cpfCnpj): array
    {
        return $this->prepareRequest()
            ->withToken($this->getToken())
            ->get("/Carteira/DadosContrato/IdEmpreendimento=$idEmpreendimento")
            ->collect()
            ->where('cod_unidade', $idUnidade)
            ->where('cpf_cnpj_cliente', $cpfCnpj)
            ->first();
    }
}
