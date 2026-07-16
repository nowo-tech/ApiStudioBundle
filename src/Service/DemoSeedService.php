<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEndpointTranslation;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiRequestExample;
use Nowo\ApiStudioBundle\Entity\ApiResponseExample;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\AuthType;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;

/**
 * Seeds reference API catalog: JSONPlaceholder, LinkedIn, Google Translate, Catastro SOAP, etc.
 */
final class DemoSeedService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiWorkspaceRepository $workspaceRepository,
        private readonly ApiServiceRepository $serviceRepository,
    ) {
    }

    public function seed(bool $fresh = false): void
    {
        if ($fresh) {
            foreach ($this->workspaceRepository->findAll() as $existing) {
                $this->entityManager->remove($existing);
            }

            $this->entityManager->flush();
        }

        $workspace = $this->ensureReferenceWorkspace();
        $this->ensureEnvironments($workspace);

        $this->seedJsonPlaceholder($workspace);
        $this->seedLinkedIn($workspace);
        $this->seedGoogleTranslate($workspace);
        $this->seedCatastroSoap($workspace);
        $this->seedCatastroRest($workspace);

        $this->entityManager->flush();
    }

    private function ensureReferenceWorkspace(): ApiWorkspace
    {
        $workspace = $this->workspaceRepository->findOneBy(['slug' => 'demo']);
        if ($workspace instanceof ApiWorkspace) {
            $workspace->setName('APIs de referencia');
            $workspace->setDescription(
                'Catálogo demo con APIs públicas documentadas: JSONPlaceholder (ejecutable), '
                . 'LinkedIn, Google Cloud Translation, Sede Electrónica del Catastro (REST y SOAP). '
                . 'Las credenciales son placeholders — sustitúyelas en Entornos antes de probar servicios reales.',
            );

            return $workspace;
        }

        $workspace = new ApiWorkspace('APIs de referencia', 'demo');
        $workspace->setDescription(
            'Catálogo demo con APIs públicas documentadas: JSONPlaceholder, LinkedIn, Google Translate, Catastro.',
        );
        $this->entityManager->persist($workspace);

        return $workspace;
    }

    private function ensureEnvironments(ApiWorkspace $workspace): void
    {
        if ($workspace->getEnvironments()->isEmpty()) {
            $sandbox = new ApiEnvironment('Sandbox', 'sandbox');
            $sandbox->setIsDefault(true);
            $this->addEnvironmentVariables($sandbox);
            $workspace->addEnvironment($sandbox);

            $production = new ApiEnvironment('Production', 'production');
            $this->addEnvironmentVariables($production);
            $workspace->addEnvironment($production);

            return;
        }

        foreach ($workspace->getEnvironments() as $environment) {
            if ($environment->getVariables()->isEmpty()) {
                $this->addEnvironmentVariables($environment);
            }
        }
    }

    private function addEnvironmentVariables(ApiEnvironment $environment): void
    {
        $variables = [
            ['jsonplaceholder_base_url', 'https://jsonplaceholder.typicode.com', false, 'Base URL JSONPlaceholder (pública, sin auth)'],
            ['linkedin_api_base', 'https://api.linkedin.com', false, 'LinkedIn API v2 root'],
            ['linkedin_access_token', 'YOUR_LINKEDIN_OAUTH2_ACCESS_TOKEN', true, 'OAuth 2.0 access token (Marketing/Sign In products)'],
            ['google_translate_base', 'https://translation.googleapis.com', false, 'Google Cloud Translation API'],
            ['google_api_key', 'YOUR_GOOGLE_CLOUD_API_KEY', true, 'API key con Translation API habilitada'],
            ['catastro_soap_wsdl', 'https://ovc.catastro.meh.es/ovcservweb/ovcswlocalizacionrc/ovccoordenadas.asmx?WSDL', false, 'WSDL OVCCoordenadas — consulta por coordenadas'],
            ['catastro_rest_base', 'https://ovc.catastro.meh.es/ovcservweb/OVCSWLocalizacionRC/OVCCoordenadas.asmx', false, 'Endpoint catastro (legacy ASMX, documentación REST-like en demo)'],
            ['catastro_srs', 'EPSG:4326', false, 'Sistema de referencia espacial (WGS84)'],
        ];

        foreach ($variables as [$key, $value, $secret, $description]) {
            if ($this->hasVariable($environment, $key)) {
                continue;
            }

            $variable = new ApiEnvironmentVariable($key, $value);
            $variable->setSecret($secret);
            $variable->setDescription($description);
            $environment->addVariable($variable);
        }
    }

    private function hasVariable(ApiEnvironment $environment, string $key): bool
    {
        foreach ($environment->getVariables() as $variable) {
            if ($variable->getVariableKey() === $key) {
                return true;
            }
        }

        return false;
    }

    private function seedJsonPlaceholder(ApiWorkspace $workspace): void
    {
        $service = $this->ensureService($workspace, 'jsonplaceholder', static function (ApiService $service): void {
            $service->setName('JSONPlaceholder');
            $service->setDescription(
                'API REST pública de prueba. Ideal para ejecutar peticiones reales desde el tester sin credenciales.',
            );
            $service->setBaseUrl('{{jsonplaceholder_base_url}}');
            $service->setProtocol(ApiProtocol::Rest);
            $service->setAuthType(AuthType::None);
            $service->setDefaultHeaders(['Accept' => 'application/json']);
        });

        $this->ensureEndpoint($service, 'list_posts', function (ApiEndpoint $endpoint): void {
            $endpoint->setName('List posts');
            $endpoint->setMethod(HttpMethod::Get);
            $endpoint->setPath('/posts');
            $endpoint->setContentType('application/json');
            $endpoint->setSortOrder(10);

            $this->addTranslations($endpoint, [
                'en' => [
                    'title'       => 'List posts',
                    'description' => 'Returns all posts. Supports optional filters via query string in real API variants.',
                    'notes'       => 'Demo: fully executable against jsonplaceholder.typicode.com',
                ],
                'es' => [
                    'title'       => 'Listar publicaciones',
                    'description' => 'Devuelve todas las publicaciones de ejemplo.',
                    'notes'       => 'Demo ejecutable sin credenciales.',
                ],
            ]);

            $this->addResponseExample($endpoint, '200 OK — array', 200, <<<'JSON'
[
  {
    "userId": 1,
    "id": 1,
    "title": "sunt aut facere repellat provident occaecati excepturi optio reprehenderit",
    "body": "quia et suscipit suscipit recusandae consequuntur expedita et cum reprehenderit molestiae ut ut quas totam"
  },
  {
    "userId": 1,
    "id": 2,
    "title": "qui est esse",
    "body": "est rerum tempore vitae sequi sint nihil reprehenderit dolor beatae ea dolores neque"
  }
]
JSON);
        });

        $this->ensureEndpoint($service, 'create_post', function (ApiEndpoint $endpoint): void {
            $endpoint->setName('Create post');
            $endpoint->setMethod(HttpMethod::Post);
            $endpoint->setPath('/posts');
            $endpoint->setContentType('application/json');
            $endpoint->setSortOrder(20);
            $endpoint->setRequestBodyTemplate(<<<'JSON'
{
  "title": "Mi publicación de prueba",
  "body": "Contenido generado desde API Studio demo.",
  "userId": 1
}
JSON);

            $this->addTranslations($endpoint, [
                'en' => [
                    'title'       => 'Create post',
                    'description' => 'Simulates creating a post. Responds with 201 and fake id 101.',
                ],
                'es' => [
                    'title'       => 'Crear publicación',
                    'description' => 'Simula la creación de una publicación. Responde 201 con id ficticio.',
                ],
            ]);

            $this->addRequestExample($endpoint, 'Minimal JSON body', <<<'JSON'
{
  "title": "foo",
  "body": "bar",
  "userId": 1
}
JSON);

            $this->addResponseExample($endpoint, '201 Created', 201, <<<'JSON'
{
  "title": "foo",
  "body": "bar",
  "userId": 1,
  "id": 101
}
JSON);
        });
    }

    private function seedLinkedIn(ApiWorkspace $workspace): void
    {
        $service = $this->ensureService($workspace, 'linkedin', static function (ApiService $service): void {
            $service->setName('LinkedIn API v2');
            $service->setDescription(
                'Documentación de referencia de LinkedIn Marketing / Sign In API. '
                . 'Requiere app en LinkedIn Developer Portal y token OAuth 2.0 con scopes adecuados.',
            );
            $service->setBaseUrl('{{linkedin_api_base}}');
            $service->setProtocol(ApiProtocol::Rest);
            $service->setAuthType(AuthType::Bearer);
            $service->setAuthConfig(['token' => '{{linkedin_access_token}}']);
            $service->setDefaultHeaders([
                'Accept'                    => 'application/json',
                'X-Restli-Protocol-Version' => '2.0.0',
                'LinkedIn-Version'          => '202405',
            ]);
        });

        $this->ensureEndpoint($service, 'get_current_member', function (ApiEndpoint $endpoint): void {
            $endpoint->setName('Get current member profile');
            $endpoint->setMethod(HttpMethod::Get);
            $endpoint->setPath('/v2/me');
            $endpoint->setContentType('application/json');
            $endpoint->setSortOrder(10);

            $this->addTranslations($endpoint, [
                'en' => [
                    'title'       => 'Get authenticated member profile',
                    'description' => 'Returns basic profile fields for the token owner. Scope typically: r_liteprofile or r_basicprofile (legacy).',
                    'notes'       => 'Official docs: https://learn.microsoft.com/en-us/linkedin/shared/integrations/people/profile-api',
                ],
                'es' => [
                    'title'       => 'Perfil del miembro autenticado',
                    'description' => 'Devuelve campos básicos del perfil del titular del token OAuth.',
                    'notes'       => 'Documentación oficial en Microsoft Learn (LinkedIn API).',
                ],
            ]);

            $this->addRequestExample($endpoint, 'GET without body', null, [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer {{linkedin_access_token}}',
            ]);

            $this->addResponseExample($endpoint, '200 OK — profile', 200, <<<'JSON'
{
  "id": "AbCdEfGhIj",
  "firstName": {
    "localized": { "en_US": "Jane" },
    "preferredLocale": { "country": "US", "language": "en" }
  },
  "lastName": {
    "localized": { "en_US": "Doe" },
    "preferredLocale": { "country": "US", "language": "en" }
  },
  "profilePicture": {
    "displayImage": "urn:li:digitalmediaAsset:C4D00AAAA..."
  }
}
JSON);

            $this->addResponseExample($endpoint, '401 Unauthorized', 401, <<<'JSON'
{
  "status": 401,
  "serviceErrorCode": 65600,
  "code": "INVALID_ACCESS_TOKEN",
  "message": "Invalid access token"
}
JSON);
        });

        $this->ensureEndpoint($service, 'create_ugc_post', function (ApiEndpoint $endpoint): void {
            $endpoint->setName('Create UGC post');
            $endpoint->setMethod(HttpMethod::Post);
            $endpoint->setPath('/v2/ugcPosts');
            $endpoint->setContentType('application/json');
            $endpoint->setSortOrder(20);
            $endpoint->setRequestBodyTemplate(<<<'JSON'
{
  "author": "urn:li:person:YOUR_MEMBER_ID",
  "lifecycleState": "PUBLISHED",
  "specificContent": {
    "com.linkedin.ugc.ShareContent": {
      "shareCommentary": {
        "text": "Publicación de prueba desde API Studio demo"
      },
      "shareMediaCategory": "NONE"
    }
  },
  "visibility": {
    "com.linkedin.ugc.MemberNetworkVisibility": "PUBLIC"
  }
}
JSON);

            $this->addTranslations($endpoint, [
                'en' => [
                    'title'       => 'Create UGC Post (share)',
                    'description' => 'Publishes a text share on behalf of the authenticated member. Requires w_member_social scope.',
                ],
                'es' => [
                    'title'       => 'Crear publicación UGC',
                    'description' => 'Publica un texto en el feed del miembro. Requiere scope w_member_social.',
                ],
            ]);

            $this->addRequestExample($endpoint, 'Text-only share', $endpoint->getRequestBodyTemplate());

            $this->addResponseExample($endpoint, '201 Created', 201, <<<'JSON'
{
  "id": "urn:li:share:7123456789012345678",
  "activity": "urn:li:activity:7123456789012345678"
}
JSON);
        });

        $this->ensureEndpoint($service, 'search_organizations', function (ApiEndpoint $endpoint): void {
            $endpoint->setName('Search organizations');
            $endpoint->setMethod(HttpMethod::Get);
            $endpoint->setPath('/v2/organizationSearch');
            $endpoint->setContentType('application/json');
            $endpoint->setQueryParams(['q' => 'search', 'query' => 'Nowo']);
            $endpoint->setSortOrder(30);

            $this->addTranslations($endpoint, [
                'en' => [
                    'title'       => 'Organization search',
                    'description' => 'Searches organizations by keywords. Query params: q=search, query=<terms>.',
                ],
                'es' => [
                    'title'       => 'Búsqueda de organizaciones',
                    'description' => 'Busca empresas por palabras clave. Parámetros: q=search, query=<términos>.',
                ],
            ]);

            $this->addResponseExample($endpoint, '200 OK — search results', 200, <<<'JSON'
{
  "elements": [
    {
      "urn": "urn:li:organization:123456",
      "name": { "localized": { "en_US": "Example Corp" } }
    }
  ],
  "paging": { "count": 10, "start": 0, "total": 1 }
}
JSON);
        });
    }

    private function seedGoogleTranslate(ApiWorkspace $workspace): void
    {
        $service = $this->ensureService($workspace, 'google_translate', static function (ApiService $service): void {
            $service->setName('Google Cloud Translation API');
            $service->setDescription(
                'Traducción automática v2 (REST). Habilitar Cloud Translation API en Google Cloud Console '
                . 'y usar API key o OAuth2 service account.',
            );
            $service->setBaseUrl('{{google_translate_base}}');
            $service->setProtocol(ApiProtocol::Rest);
            $service->setAuthType(AuthType::ApiKey);
            $service->setAuthConfig([
                'header' => 'X-Goog-Api-Key',
                'value'  => '{{google_api_key}}',
            ]);
            $service->setDefaultHeaders(['Accept' => 'application/json']);
        });

        $this->ensureEndpoint($service, 'translate_text', function (ApiEndpoint $endpoint): void {
            $endpoint->setName('Translate text');
            $endpoint->setMethod(HttpMethod::Post);
            $endpoint->setPath('/language/translate/v2');
            $endpoint->setContentType('application/json');
            $endpoint->setQueryParams(['key' => '{{google_api_key}}']);
            $endpoint->setSortOrder(10);
            $endpoint->setRequestBodyTemplate(<<<'JSON'
{
  "q": ["Hello world", "Good morning"],
  "target": "es",
  "source": "en",
  "format": "text"
}
JSON);

            $this->addTranslations($endpoint, [
                'en' => [
                    'title'       => 'Translate text (v2)',
                    'description' => 'Translates one or more strings to target language. Max 128 strings per request.',
                    'notes'       => 'Docs: https://cloud.google.com/translate/docs/reference/rest/v2/translate/translate',
                ],
                'es' => [
                    'title'       => 'Traducir texto (v2)',
                    'description' => 'Traduce una o más cadenas al idioma destino.',
                    'notes'       => 'Documentación Google Cloud Translation API v2.',
                ],
            ]);

            $this->addRequestExample($endpoint, 'EN → ES batch', $endpoint->getRequestBodyTemplate());

            $this->addResponseExample($endpoint, '200 OK — translations', 200, <<<'JSON'
{
  "data": {
    "translations": [
      { "translatedText": "Hola mundo", "detectedSourceLanguage": "en" },
      { "translatedText": "Buenos días", "detectedSourceLanguage": "en" }
    ]
  }
}
JSON);

            $this->addResponseExample($endpoint, '400 Invalid target language', 400, <<<'JSON'
{
  "error": {
    "code": 400,
    "message": "Invalid Value: target",
    "errors": [{ "message": "Invalid Value: target", "domain": "global", "reason": "invalid" }]
  }
}
JSON);
        });

        $this->ensureEndpoint($service, 'detect_language', function (ApiEndpoint $endpoint): void {
            $endpoint->setName('Detect language');
            $endpoint->setMethod(HttpMethod::Post);
            $endpoint->setPath('/language/translate/v2/detect');
            $endpoint->setContentType('application/json');
            $endpoint->setQueryParams(['key' => '{{google_api_key}}']);
            $endpoint->setSortOrder(20);
            $endpoint->setRequestBodyTemplate(<<<'JSON'
{
  "q": ["Bonjour le monde", "Hola mundo"]
}
JSON);

            $this->addTranslations($endpoint, [
                'en' => [
                    'title'       => 'Detect language',
                    'description' => 'Detects the language of input strings.',
                ],
                'es' => [
                    'title'       => 'Detectar idioma',
                    'description' => 'Detecta el idioma de las cadenas de entrada.',
                ],
            ]);

            $this->addResponseExample($endpoint, '200 OK — detections', 200, <<<'JSON'
{
  "data": {
    "detections": [
      [{ "language": "fr", "confidence": 0.98 }],
      [{ "language": "es", "confidence": 0.99 }]
    ]
  }
}
JSON);
        });

        $this->ensureEndpoint($service, 'list_languages', function (ApiEndpoint $endpoint): void {
            $endpoint->setName('List supported languages');
            $endpoint->setMethod(HttpMethod::Get);
            $endpoint->setPath('/language/translate/v2/languages');
            $endpoint->setQueryParams(['key' => '{{google_api_key}}', 'target' => 'es']);
            $endpoint->setSortOrder(30);

            $this->addTranslations($endpoint, [
                'en' => [
                    'title'       => 'List supported languages',
                    'description' => 'Returns BCP-47 language codes supported for translation. Optional target param localizes language names.',
                ],
                'es' => [
                    'title'       => 'Listar idiomas soportados',
                    'description' => 'Devuelve códigos de idioma BCP-47 soportados.',
                ],
            ]);

            $this->addResponseExample($endpoint, '200 OK — languages', 200, <<<'JSON'
{
  "data": {
    "languages": [
      { "language": "en", "name": "inglés" },
      { "language": "es", "name": "español" },
      { "language": "fr", "name": "francés" }
    ]
  }
}
JSON);
        });
    }

    private function seedCatastroSoap(ApiWorkspace $workspace): void
    {
        $service = $this->ensureService($workspace, 'catastro_soap', static function (ApiService $service): void {
            $service->setName('Catastro — OVCCoordenadas (SOAP)');
            $service->setDescription(
                'Servicio SOAP de la Sede Electrónica del Catastro (Ministerio de Hacienda, España). '
                . 'Consulta de referencia catastral a partir de coordenadas geográficas. '
                . 'WSDL público sin autenticación para consultas no protegidas.',
            );
            $service->setBaseUrl('{{catastro_soap_wsdl}}');
            $service->setProtocol(ApiProtocol::Soap);
            $service->setAuthType(AuthType::None);
            $service->setDefaultHeaders(['Content-Type' => 'text/xml; charset=utf-8']);
        });

        $this->ensureEndpoint($service, 'consulta_cpmrc', function (ApiEndpoint $endpoint): void {
            $endpoint->setName('Consulta_CPMRC');
            $endpoint->setMethod(HttpMethod::Post);
            $endpoint->setPath('/');
            $endpoint->setSoapAction('Consulta_CPMRC');
            $endpoint->setContentType('text/xml; charset=utf-8');
            $endpoint->setSortOrder(10);
            $endpoint->setRequestBodyTemplate(<<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <Consulta_CPMRC xmlns="http://www.catastro.meh.es/">
      <SRS>{{catastro_srs}}</SRS>
      <Coordenada_X>-3.703790</Coordenada_X>
      <Coordenada_Y>40.416775</Coordenada_Y>
    </Consulta_CPMRC>
  </soap:Body>
</soap:Envelope>
XML);

            $this->addTranslations($endpoint, [
                'en' => [
                    'title'       => 'Cadastral lookup by coordinates (SOAP)',
                    'description' => 'Returns cadastral parcel references for a point (lon/lat in SRS). Example: Puerta del Sol, Madrid.',
                    'notes'       => 'WSDL: ovc.catastro.meh.es — Operación Consulta_CPMRC. Demo SOAP en API Studio.',
                ],
                'es' => [
                    'title'       => 'Consulta catastral por coordenadas (SOAP)',
                    'description' => 'Devuelve referencias catastrales de la parcela en un punto (lon/lat según SRS). Ejemplo: Puerta del Sol, Madrid.',
                    'notes'       => 'Servicio público de consulta de datos catastrales no protegidos.',
                ],
            ]);

            $this->addRequestExample($endpoint, 'Puerta del Sol (WGS84)', $endpoint->getRequestBodyTemplate());

            $this->addRequestExample($endpoint, 'Parámetros JSON para SoapClient', <<<'JSON'
{
  "SRS": "EPSG:4326",
  "Coordenada_X": -3.703790,
  "Coordenada_Y": 40.416775
}
JSON);

            $this->addResponseExample($endpoint, '200 OK — SOAP response (extract)', 200, <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <Consulta_CPMRCResponse xmlns="http://www.catastro.meh.es/">
      <consulta_cpmrcResult>
        <coordenadas>
          <coord>
            <pc><pc1>0076013</pc1><pc2>DF29</pc2><pc3>0001</pc3><pc4>0001</pc4></pc>
            <geo><xcen>-3.70379</xcen><ycen>40.416775</ycen></geo>
          </coord>
        </coordenadas>
      </consulta_cpmrcResult>
    </Consulta_CPMRCResponse>
  </soap:Body>
</soap:Envelope>
XML);

            $this->addResponseExample($endpoint, 'Fault — coordenada invalida', 500, <<<'XML'
<soap:Fault>
  <faultcode>soap:Server</faultcode>
  <faultstring>Coordenada fuera de rango</faultstring>
</soap:Fault>
XML);
        });

        $this->ensureEndpoint($service, 'consulta_dnprc', function (ApiEndpoint $endpoint): void {
            $endpoint->setName('Consulta_DNPRC');
            $endpoint->setMethod(HttpMethod::Post);
            $endpoint->setPath('/');
            $endpoint->setSoapAction('Consulta_DNPRC');
            $endpoint->setContentType('text/xml; charset=utf-8');
            $endpoint->setSortOrder(20);
            $endpoint->setRequestBodyTemplate(<<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <Consulta_DNPRC xmlns="http://www.catastro.meh.es/">
      <Provincia>MADRID</Provincia>
      <Municipio>MADRID</Municipio>
      <RC>0076013DF2900010001</RC>
    </Consulta_DNPRC>
  </soap:Body>
</soap:Envelope>
XML);

            $this->addTranslations($endpoint, [
                'en' => [
                    'title'       => 'Descriptive cadastral data by reference (SOAP)',
                    'description' => 'Returns descriptive non-protected cadastral data for a 14/20-char reference (RC).',
                ],
                'es' => [
                    'title'       => 'Consulta descriptiva por referencia catastral (SOAP)',
                    'description' => 'Datos descriptivos no protegidos de una finca a partir de su referencia catastral (RC).',
                ],
            ]);

            $this->addRequestExample($endpoint, 'RC completa Madrid centro', $endpoint->getRequestBodyTemplate());

            $this->addResponseExample($endpoint, '200 OK — datos descriptivos', 200, <<<'XML'
<Consulta_DNPRCResponse>
  <bico>
    <bi><idbi><cn>UR</cn><rc>0076013DF2900010001</rc></idbi></bi>
    <ldt>CL GRAN VIA 1 Madrid (Madrid)</ldt>
    <debi><ant>100</ant><cpt>100</cpt><luso>Comercial</luso></debi>
  </bico>
</Consulta_DNPRCResponse>
XML);
        });
    }

    private function seedCatastroRest(ApiWorkspace $workspace): void
    {
        $service = $this->ensureService($workspace, 'catastro_rest', static function (ApiService $service): void {
            $service->setName('Catastro — Consulta REST (OVC)');
            $service->setDescription(
                'Endpoints HTTP de consulta catastral no protegida (documentación de referencia). '
                . 'Incluye consulta por RC y por coordenadas en formato XML/JSON según servicio.',
            );
            $service->setBaseUrl('https://ovc.catastro.meh.es/ovcservweb/OVCSWLocalizacionRC');
            $service->setProtocol(ApiProtocol::Rest);
            $service->setAuthType(AuthType::None);
            $service->setDefaultHeaders(['Accept' => 'application/xml']);
        });

        $this->ensureEndpoint($service, 'consulta_rc', function (ApiEndpoint $endpoint): void {
            $endpoint->setName('Consulta por referencia catastral');
            $endpoint->setMethod(HttpMethod::Get);
            $endpoint->setPath('/Consulta_DNPRC');
            $endpoint->setContentType('application/xml');
            $endpoint->setQueryParams([
                'Provincia' => 'MADRID',
                'Municipio' => 'MADRID',
                'RC'        => '0076013DF2900010001',
            ]);
            $endpoint->setSortOrder(10);

            $this->addTranslations($endpoint, [
                'en' => [
                    'title'       => 'Cadastral reference lookup (HTTP)',
                    'description' => 'HTTP GET variant documented for cadastral descriptive queries by province, municipality and RC.',
                ],
                'es' => [
                    'title'       => 'Consulta por referencia catastral (HTTP)',
                    'description' => 'Variante HTTP GET para consulta descriptiva por provincia, municipio y RC.',
                    'notes'       => 'Administración pública — datos no protegidos.',
                ],
            ]);

            $this->addResponseExample($endpoint, '200 OK — XML', 200, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<consulta_dnprc xmlns="http://www.catastro.meh.es/">
  <bico>
    <bi><idbi><cn>UR</cn><rc>0076013DF2900010001</rc></idbi></bi>
    <ldt>CL GRAN VIA 1 Madrid (Madrid)</ldt>
  </bico>
</consulta_dnprc>
XML);
        });

        $this->ensureEndpoint($service, 'consulta_coordenadas', function (ApiEndpoint $endpoint): void {
            $endpoint->setName('Consulta por coordenadas');
            $endpoint->setMethod(HttpMethod::Get);
            $endpoint->setPath('/OVCCoordenadas.asmx/Consulta_CPMRC');
            $endpoint->setContentType('application/xml');
            $endpoint->setQueryParams([
                'SRS'          => '{{catastro_srs}}',
                'Coordenada_X' => '-3.703790',
                'Coordenada_Y' => '40.416775',
            ]);
            $endpoint->setSortOrder(20);

            $this->addTranslations($endpoint, [
                'en' => [
                    'title'       => 'Lookup RC by coordinates (HTTP GET)',
                    'description' => 'Returns cadastral references for geographic coordinates. Same logic as SOAP Consulta_CPMRC.',
                ],
                'es' => [
                    'title'       => 'Consulta RC por coordenadas (HTTP GET)',
                    'description' => 'Obtiene referencias catastrales desde coordenadas geográficas.',
                ],
            ]);

            $this->addResponseExample($endpoint, '200 OK — XML coordenadas', 200, <<<'XML'
<?xml version="1.0"?>
<consulta_cpmrc>
  <coordenadas>
    <coord>
      <pc><pc1>0076013</pc1><pc2>DF29</pc2><pc3>0001</pc3><pc4>0001</pc4></pc>
      <geo><xcen>-3.70379</xcen><ycen>40.416775</ycen></geo>
    </coord>
  </coordenadas>
</consulta_cpmrc>
XML);
        });
    }

    private function ensureService(ApiWorkspace $workspace, string $slug, callable $configure): ApiService
    {
        $existing = $this->serviceRepository->findOneBy(['workspace' => $workspace, 'slug' => $slug]);
        if ($existing instanceof ApiService) {
            return $existing;
        }

        $service = new ApiService(ucfirst(str_replace('_', ' ', $slug)), $slug);
        $workspace->addService($service);
        $configure($service);
        $this->entityManager->persist($service);

        return $service;
    }

    /**
     * @param callable(ApiEndpoint): void $configure
     */
    private function ensureEndpoint(ApiService $service, string $slug, callable $configure): ApiEndpoint
    {
        foreach ($service->getEndpoints() as $endpoint) {
            if ($endpoint->getSlug() === $slug) {
                return $endpoint;
            }
        }

        $endpoint = new ApiEndpoint(ucfirst(str_replace('_', ' ', $slug)), $slug);
        $service->addEndpoint($endpoint);
        $configure($endpoint);
        $this->entityManager->persist($endpoint);

        return $endpoint;
    }

    /**
     * @param array<string, array{title?: string, description?: string, notes?: string}> $locales
     */
    private function addTranslations(ApiEndpoint $endpoint, array $locales): void
    {
        foreach ($locales as $locale => $data) {
            if ($endpoint->getTranslation($locale) !== null) {
                continue;
            }

            $translation = new ApiEndpointTranslation($locale);
            $translation->setTitle($data['title'] ?? null);
            $translation->setDescription($data['description'] ?? null);
            $translation->setNotes($data['notes'] ?? null);
            $endpoint->addTranslation($translation);
        }
    }

    /** @param array<string, string> $headers */
    private function addRequestExample(
        ApiEndpoint $endpoint,
        string $name,
        ?string $body,
        array $headers = [],
    ): void {
        foreach ($endpoint->getRequestExamples() as $example) {
            if ($example->getName() === $name) {
                return;
            }
        }

        $example = new ApiRequestExample($name);
        $example->setRequestBody($body);
        if ($headers !== []) {
            $example->setHeaders($headers);
        }

        $endpoint->addRequestExample($example);
    }

    private function addResponseExample(
        ApiEndpoint $endpoint,
        string $name,
        int $statusCode,
        string $body,
    ): void {
        foreach ($endpoint->getResponseExamples() as $example) {
            if ($example->getName() === $name) {
                return;
            }
        }

        $example = new ApiResponseExample($name, $statusCode);
        $example->setResponseBody($body);
        $endpoint->addResponseExample($example);
    }
}
