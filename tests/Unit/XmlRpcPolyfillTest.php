<?php
/**
 * The xmlrpc_* functions of the library standing for the extension, as query.php, currency.php and the others use them.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
// Where the extension is, its functions answer instead, which is what the polyfill imitates
if (!function_exists('xmlrpc_encode')) {
    require_once dirname(__DIR__, 2) . '/includes/library-xmlrpc.php';
}

/**
 * Calls the server as a script does, and leaves the output buffers and the error handlers as it found them: the library leaves
 * its own buffers, and stacks the previous error handler on top of its own instead of removing it.
 *
 * @return array{0:mixed,1:string} what the call returned, what it printed
 */
function xmlrpc_called(callable $call): array
{
    $level = ob_get_level();
    // A handler of its own on top of the one found, so that every handler above the one found can be removed
    $probe = static fn() => false;
    $found = set_error_handler($probe);
    ob_start();
    $returned = $call();
    $printed = '';
    while (ob_get_level() > $level) {
        $printed = ob_get_clean() . $printed;
    }
    for ($i = 0; $i < 10; $i++) {
        $current = set_error_handler($probe);
        restore_error_handler();
        if ($current === $found) {
            break;
        }
        restore_error_handler();
    }

    return [$returned, $printed];
}

function xmlrpc_double($method, $params, $app_data)
{
    // What OpenSim sends: one struct of strings; what it expects back: a struct
    return ['method' => $method, 'text' => $params[0]['text'] . '!', 'data' => $app_data];
}

test('serves a method with the signature of the extension, and returns its answer without printing it', function () {
    $server = xmlrpc_server_create();
    expect(xmlrpc_server_register_method($server, 'dir_places_query', 'xmlrpc_double'))->toBeTrue();

    $request =
        '<?xml version="1.0"?><methodCall><methodName>dir_places_query</methodName><params><param><value><struct>' .
        '<member><name>text</name><value><string>Welcome</string></value></member></struct></value></param></params></methodCall>';
    [$response, $printed] = xmlrpc_called(static fn() => xmlrpc_server_call_method($server, $request, 'the data'));

    $answer = xmlrpc_decode($response);
    expect($printed)
        ->toBe('')
        ->and($answer['method'])
        ->toBe('dir_places_query')
        ->and($answer['text'])
        ->toBe('Welcome!')
        ->and($answer['data'])
        ->toBe('the data');
});

function xmlrpc_prints($method, $params, $app_data)
{
    // What query.php and currency.php do: the method prints its answer, as with the extension
    echo xmlrpc_encode(['success' => true, 'errorMessage' => '']);
}

test('gives one document when the method prints its own answer, as the extension does', function () {
    $server = xmlrpc_server_create();
    xmlrpc_server_register_method($server, 'dir_popular_query', 'xmlrpc_prints');

    [, $response] = xmlrpc_called(static fn() => xmlrpc_server_call_method(
        $server,
        '<?xml version="1.0"?><methodCall><methodName>dir_popular_query</methodName><params></params></methodCall>',
        '',
    ));

    $document = new DOMDocument();
    expect(substr_count($response, '<?xml'))
        ->toBe(1)
        ->and(@$document->loadXML($response))
        ->toBeTrue()
        ->and($response)
        ->toContain('<name>success</name>');
});

test('returns a fault for a method that is not registered', function () {
    $server = xmlrpc_server_create();
    $response = xmlrpc_server_call_method(
        $server,
        '<?xml version="1.0"?><methodCall><methodName>nope</methodName><params></params></methodCall>',
        null,
    );

    expect($response)->toContain('<fault>');
});

test('encodes a value as the XML parameters of a response, like the extension', function () {
    $xml = xmlrpc_encode(['success' => true]);

    expect($xml)->toBeString()->toContain('<params>')->toContain('<name>success</name>');
});
