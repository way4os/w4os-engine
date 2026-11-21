<?php

namespace W4OS\Engine\Hypergrid;

/**
 * What a gatekeeper tells of its regions (link_region, get_region), asked by XML-RPC, answers kept for the request.
 */
final class Gatekeeper
{
    /** @var bool whether the certificate of a gatekeeper must be one somebody signed (a grid with its own can say it is not) */
    public static bool $verifyPeer = true;

    /** How long a gatekeeper has to answer, in seconds: most answer below one. */
    public const TIMEOUT = 3;

    /** How many gatekeepers are asked at once. */
    public const CONCURRENCY = 8;

    /** @var array<string,array<string,mixed>> what the gatekeepers answered during this request, by call and key */
    private static array $cache = [];

    /** @var ?\Closure(string,string):?string the answer of a gatekeeper to a call (its address, the call), a test's */
    private static ?\Closure $transport = null;

    /**
     * Ask the gatekeepers with another function (a test's), or over HTTP again with null; what they answered is forgotten.
     *
     * @param ?\Closure(string,string):?string $transport the body of the answer, null when there is none
     */
    public static function using(?\Closure $transport): void
    {
        self::$transport = $transport;
        self::forget();
    }

    /** Forget what the gatekeepers answered: the next calls ask them again. */
    public static function forget(): void
    {
        self::$cache = [];
    }

    /**
     * [oxXmlRequest description]
     *
     * @param  string $gatekeeper               [description]
     * @param  string $method                   [description]
     * @param  array  $request                  [description]
     * @return array             received xml response
     */
    public static function request($gatekeeper, $method, $request)
    {
        if (!is_string($gatekeeper) || trim($gatekeeper) === '') {
            return false;
        }
        if (!function_exists('xmlrpc_encode_request')) {
            error_log('[ERROR] (XML RPC missing) self::request() requires xmlrpc_encode_request()');
            return false;
        }
        // A host that did not answer during this run is not asked again
        if (isset(self::$cache['down'][self::host($gatekeeper)])) {
            return false;
        }
        $xml_request = xmlrpc_encode_request($method, [$request]); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: text/xml' . "\r\n",
                'timeout' => self::TIMEOUT, // most of the time below 1 sec, but leave some time for slow ones
                'content' => $xml_request,
            ],
        ];
        if (!self::$verifyPeer) {
            $options['ssl'] = ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true];
        }
        $context = stream_context_create($options);

        $response = self::$transport !== null ? (self::$transport)($gatekeeper, $xml_request) ?? false : @file_get_contents($gatekeeper, false, $context);
        if ($response === false) {
            self::$cache['down'][self::host($gatekeeper)] = true;

            return false;
        }

        return self::decode($gatekeeper, $method, $response);
    }

    /** What a gatekeeper answered, as an array; false for an answer that is none, or a fault. */
    private static function decode($gatekeeper, $method, $response)
    {
        if (empty($response)) {
            return false;
        }

        try {
            $encoder = new \PhpXmlRpc\Encoder();
            $decoded = $encoder->decodeXml($response);
        } catch (\Throwable $e) {
            error_log("oxXmlRequest decode error ($gatekeeper $method): " . $e->getMessage());
            return false;
        }
        if (empty($decoded)) {
            return false;
        }
        if ($decoded instanceof \PhpXmlRpc\Response) {
            if ($decoded->faultCode()) {
                error_log("oxXmlRequest fault ($gatekeeper $method): " . $decoded->faultCode() . ' ' . $decoded->faultString());
                return false;
            }
            $xml_array = $encoder->decode($decoded->value());
        } else {
            $xml_array = $decoded;
        }
        if (empty($xml_array) || !is_array($xml_array)) {
            return false;
        }
        if (xmlrpc_is_fault($xml_array)) {
            // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions
            return false;
        }
        return $xml_array;
    }

    /** The host and the port of a gatekeeper, what tells one server from another. */
    private static function host(string $gatekeeper): string
    {
        $parts = parse_url(preg_match('#://#', $gatekeeper) ? $gatekeeper : "http://$gatekeeper") ?: [];

        return strtolower(($parts['host'] ?? $gatekeeper) . ':' . ($parts['port'] ?? 80));
    }

    /**
     * Ask the gatekeepers of these places what linkRegion() and region() ask, all at once, a few at a time (CONCURRENCY), each within
     * the TIMEOUT: their answers are remembered for the run, so that the places are then known without waiting for one after the
     * other. A host that does not answer is not asked again.
     *
     * @param list<string> $places region addresses (host:port:Region or host:port/Region...)
     */
    public static function prefetch(array $places): void
    {
        $links = [];
        foreach ($places as $place) {
            $region = is_string($place) && trim($place) !== '' ? \W4OS\Engine\Hypergrid\Destination::parse($place) : null;
            if (empty($region['gatekeeper']) || empty($region['region_uri']) || isset(self::$cache['link_region'][$region['region_uri']])) {
                continue;
            }
            $gatekeeper = preg_match('#://#', $region['gatekeeper']) ? $region['gatekeeper'] : 'http://' . $region['gatekeeper'];
            $links[$region['region_uri']] ??= [$gatekeeper, 'link_region', ['region_name' => $region['region'] ?? '']];
        }
        foreach (self::many($links) as $uri => $answer) {
            self::$cache['link_region'][$uri] = $answer;
        }
        $regions = [];
        foreach ($links as $uri => [$gatekeeper]) {
            $uuid = self::$cache['link_region'][$uri]['uuid'] ?? null;
            \W4OS\Engine\Text\Uuid::isValid((string) $uuid) && !isset(self::$cache['get_region'][$uuid])
                && ($regions[$uuid] ??= [$gatekeeper, 'get_region', ['region_uuid' => (string) $uuid]]);
        }
        foreach (self::many($regions) as $uuid => $answer) {
            self::$cache['get_region'][$uuid] = $answer;
        }
    }

    /**
     * Ask several gatekeepers at once.
     *
     * @param array<string,array{0:string,1:string,2:array<string,mixed>}> $requests by key: gatekeeper, method, request
     * @return array<string,array<string,mixed>|false> by key, what each answered
     */
    private static function many(array $requests): array
    {
        if ($requests === [] || !function_exists('xmlrpc_encode_request')) {
            return [];
        }
        // A transport of its own (the tests): one after the other, as request() asks
        if (self::$transport !== null || !function_exists('curl_multi_init')) {
            return array_map(static fn(array $asked) => self::request(...$asked), $requests);
        }
        $answers = [];
        $waiting = $requests;
        $running = [];
        $multi = curl_multi_init();
        do {
            while (count($running) < self::CONCURRENCY && $waiting !== []) {
                $key = (string) array_key_first($waiting);
                [$gatekeeper, $method, $request] = array_shift($waiting);
                if (isset(self::$cache['down'][self::host($gatekeeper)])) {
                    $answers[$key] = false;
                    continue;
                }
                $handle = curl_init($gatekeeper);
                curl_setopt_array($handle, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => xmlrpc_encode_request($method, [$request]), // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions
                    CURLOPT_HTTPHEADER => ['Content-Type: text/xml'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => self::TIMEOUT,
                    CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
                    CURLOPT_SSL_VERIFYPEER => self::$verifyPeer,
                    CURLOPT_SSL_VERIFYHOST => self::$verifyPeer ? 2 : 0,
                ]);
                curl_multi_add_handle($multi, $handle);
                $running[spl_object_id($handle)] = [$key, $handle, $gatekeeper, $method];
            }
            curl_multi_exec($multi, $active);
            $active && curl_multi_select($multi, 0.2);
            while (($done = curl_multi_info_read($multi)) !== false) {
                [$key, $handle, $gatekeeper, $method] = $running[spl_object_id($done['handle'])];
                unset($running[spl_object_id($done['handle'])]);
                $body = (int) $done['result'] === 0 ? (string) curl_multi_getcontent($handle) : null;
                $body === null && (self::$cache['down'][self::host($gatekeeper)] = true);
                $answers[$key] = $body === null ? false : self::decode($gatekeeper, $method, $body);
                curl_multi_remove_handle($multi, $handle);
            }
        } while ($running !== [] || $waiting !== []);
        curl_multi_close($multi);

        return $answers;
    }

    public static function post($requestURL, $request)
    {
        if (empty($request)) {
            return [];
        }
        if (!function_exists('xmlrpc_decode')) {
            // Avoid crash but log error
            error_log('[ERROR] (XML RPC missing) self::post() requires xmlrpc_decode()');
            return false;
        }
        $enable_self_signed = !self::$verifyPeer;

        $options = [
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: text/xml' . "\r\n",
                'content' => $request,
            ],
        ];

        // Add SSL context options if self-signed certificates should be accepted
        if ($enable_self_signed) {
            $options['ssl'] = [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ];
        }

        $context = stream_context_create($options);
        $response = xmlrpc_decode(file_get_contents($requestURL, false, $context));
        if (is_array($response) && !xmlrpc_is_fault($response) && !empty($response) && isset($response['data'])) {
            return $response['data'];
        } else {
            return [];
        }
    }

    /**
     * Use xmlrpc link_region method to request link_region data from robust
     *
     * @param  mixed  $args   region uri or sanitized region array
     * @param  string $var      output a single variable value
     * @return array (or string if var specified)
     * Array format:
     *  - uuid: UUID of the region
     *
     */
    public static function linkRegion($args, $var = null)
    {
        if (empty($args)) {
            error_log('DEBUG ' . __FUNCTION__ . ' empty args');
            return [];
        }
        if (is_string($args)) {
            $region_array = \W4OS\Engine\Hypergrid\Destination::parse($args);
        } elseif (is_array($args)) {
            if (!empty($args['region_uri'])) {
                $region_array = $args;
            } elseif ($args['host'] || $args['region']) {
                $tryurl = join('/', [join(':', [$args['host'] ?? '', $args['port'] ?? '']), $args['region'] ?? '']);

                $region_array = \W4OS\Engine\Hypergrid\Destination::parse($tryurl);
            }
        }
        if (!$region_array) {
            return [
                'success' => false,
                'errorMessage' => 'could not parse region url from args: ' . print_r($args, true),
                'data' => $region_array,
                'args' => $args,
            ];
        }
        extract($region_array); // $host, $port, $region, $pos, $gatekeeper, $region_uri, $dest_uri
        if (empty($gatekeeper)) {
            // TODO: implemeent default gatekeeper and apply if region name is provided alone
            return [
                'success' => false,
                'errorMessage' => 'no gatekeeper',
                'data' => $region_array,
                'args' => $args,
            ];
        }
        if (empty($region_uri)) {
            return [
                'success' => false,
                'errorMessage' => 'could not parse region uri from region_array: ' . print_r($region_array, true),
                'data' => $region_array,
                'args' => $args,
            ];
        }
        $gatekeeper = preg_match('#://#', $gatekeeper) ? $gatekeeper : "http://$gatekeeper";

        if (isset(self::$cache['link_region'][$region_uri])) {
            $link_region = self::$cache['link_region'][$region_uri];
        } else {
            $link_region = self::request($gatekeeper, 'link_region', [
                'region_name' => $region ?? '',
            ]);
            self::$cache['link_region'][$region_uri] = $link_region;
        }

        if ($link_region) {
            if ($var) {
                return $link_region[$var];
            } else {
                return $link_region;
            }
        }

        return [];
    }

    /**
     * Get region data from region name or UUID
     *
     * @param  string|array $region region name or UUID or sanitized region array
     * @param  string $var   optional variable to return
     * @return array
     */
    public static function region($region, $var = null)
    {
        if (empty($region)) {
            return [
                'errorCode' => 400,
                'error' => 'Empty region',
            ];
        }
        if (\W4OS\Engine\Text\Uuid::isValid($region)) {
            // Not implemented, UUID lookup would require a default gatekeeper
            return [
                'errorCode' => 501,
                'error' => 'UUID lookup not implemented',
            ];
        }
        $region = \W4OS\Engine\Hypergrid\Destination::parse($region);
        $link_region = self::linkRegion($region);
        if (!\W4OS\Engine\Text\Uuid::isValid($link_region['uuid'] ?? false)) {
            return [
                'errorCode' => 400,
                'error' => 'Invalid region UUID',
            ];
        }

        if (empty($region['gatekeeper'])) {
            return [
                'errorCode' => 400,
                'error' => 'Empty gatekeeper',
            ];
        }
        extract($region);
        $gatekeeper = preg_match('#://#', $gatekeeper) ? $gatekeeper : "http://$gatekeeper";

        $uuid = $link_region['uuid'] ?? false;
        if (isset(self::$cache['get_region'][$uuid])) {
            $get_region = self::$cache['get_region'][$uuid];
        } else {
            $get_region = self::request($gatekeeper, 'get_region', [
                'region_uuid' => "$uuid",
            ]);
            self::$cache['get_region'][$uuid] = $get_region;
        }

        if ($get_region) {
            // What the gatekeeper said of the link, with it
            $get_region['link_region'] = $link_region;
            if ($var) {
                return $get_region[$var];
            } else {
                return $get_region;
            }
        }
        return [];
    }

    /**
     * Check if region is online
     *
     * @param  mixed $region   region uri or sanitized region array
     * @return boolean                  true if online
     */
    public static function regionIsOnline($region)
    {
        $link_region = self::linkRegion($region);

        // About `result = "True"` vs `uuid != NULL_KEY`
        //
        // In some circumstances, maybe one of them will be matched
        // and the other will not, while region is found but offline.
        // In doubt, rely on semantics: "result" = "True" means a result
        // was found, not specifically that it's a good result.
        //
        // handle or uuid are better indicators of a good result.

        return (bool) \W4OS\Engine\Text\Uuid::isValid($link_region['uuid'] ?? false);
    }
}
