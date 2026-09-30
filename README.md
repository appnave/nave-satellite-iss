# bildvitta/iss-satellite

Pacote privado da Nave para integrações Laravel com Mega, Mega Cloud, WSCarteira, Finnet, Multidados e SSH.

## Visão geral

- Nome do pacote: `bildvitta/iss-satellite`
- Namespace principal: `Nave\IssSatellite`
- Publica apenas configuração, sem rotas, views ou migrations por padrão

## Requisitos

- PHP `^8.3`
- Laravel `10`, `11` ou `12`
- `ext-oci8` e Oracle Instant Client para uso do `Mega`
- `ext-soap` para `WsCarteira` e `Multidados`
- Credenciais e endpoints configurados no `.env`

## Acesso a repositórios privados

No projeto cliente, adicione o repositório VCS no `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/ORG/REPO"
        }
    ]
}
```

Depois instale o pacote:

```bash
composer require bildvitta/iss-satellite
```

Autenticação local do Composer com token do GitHub:

```bash
composer config -g github-oauth.github.com <YOUR_TOKEN>
```

No GitHub Actions, configure `COMPOSER_AUTH` antes do `composer install`:

```yaml
env:
  COMPOSER_AUTH: >-
    {"github-oauth":{"github.com":"${{ secrets.COMPOSER_GITHUB_TOKEN }}"}}
```

## Instalação local

No projeto cliente:

1. Adicione o repositório privado no `composer.json`.
2. Instale o pacote com `composer require bildvitta/iss-satellite`.
3. Publique a configuração.
4. Preencha as variáveis de ambiente necessárias.

Publicar configuração:

```bash
php artisan vendor:publish --tag=iss-satellite-config
```

As chaves disponíveis ficam em `config/iss-satellite.php`. Use apenas as integrações que o projeto realmente precisar.

## Comandos úteis

```bash
php artisan vendor:publish --tag=iss-satellite-config
composer analyse
composer test
composer test-coverage
composer format
```

## Convenções do projeto

Classes públicas disponíveis:

```php
use Nave\IssSatellite\Finnet;
use Nave\IssSatellite\Mega;
use Nave\IssSatellite\MegaCloud\MegaCloud;
use Nave\IssSatellite\Multidados;
use Nave\IssSatellite\Ssh;
use Nave\IssSatellite\WsCarteira;
```

- `Mega` usa a conexão Oracle configurada em `iss-satellite.mega.db`
- `Ssh` abre túnel SSH para conexões configuradas
- `Finnet`, `MegaCloud`, `WsCarteira` e `Multidados` dependem de credenciais que vem do hub

## Uso básico

```php
use Nave\IssSatellite\Mega;
use Nave\IssSatellite\MegaCloud;
use Nave\IssSatellite\Facades\Ssh;
$megaCredentials = [
    'host' => $credentials['mega_db']['credentials']['MEGA_DB_HOST'],
    'port' => $credentials['mega_db']['credentials']['MEGA_TUNNEL_LOCAL_PORT'],
    'database' => $credentials['mega_db']['credentials']['MEGA_DB_DATABASE'],
    'username' => $credentials['mega_db']['credentials']['MEGA_DB_USERNAME'],
    'password' => $credentials['mega_db']['credentials']['MEGA_DB_PASSWORD'],
    'connection_name_prefix' => $credentials['mega_db']['credentials']['CONNECTION_NAME_PREFIX'],
];

Mega::setConnectionData($megaCredentials);
$rows = Mega::connection()->select('select * from EXAMPLE');

$sshConfig = [
    'HOST' => '150.47.109.80',
    'USERNAME' => 'user',
    'PASSWORD' => 'password',
    'TUNNEL' => '238.33.98.211',
    'LOCAL_PORT' => 1521,
    'DESTINATION_PORT' => 1521,
];
Ssh::connect($sshConfig);

$megaCloud = resolve(MegaCloud::class)->setCredentials([
    $credentials['MEGA_CLOUD_HOST'],
    $credentials['MEGA_CLOUD_PREFIX'],
    $credentials['MEGA_CLOUD_USER'],
    $credentials['MEGA_CLOUD_PASSWORD'],
    $credentials['MEGA_CLOUD_TENANT'],
]);
$response = $megaCloud->get('/globalestruturas/Empreendimentos');
```

## Informações adicionais

- Consulte `CHANGELOG.md` para histórico de mudanças
- Licença: MIT
