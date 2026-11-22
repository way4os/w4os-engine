<?php

declare(strict_types=1);

/**
 * The answers of XML-RPC the simulators and the viewers read: a fault for what a caller only logs.
 */

use W4OS\Engine\Services\XmlRpc;

describe('A fault of XML-RPC', function () {
    test('is a methodResponse with its code and its text, read back as a fault, not as an answer that failed', function () {
        $fault = XmlRpc::fault(404, 'query.php is not provided here <here>');

        expect($fault)->toContain('<methodResponse>', '<fault>', '<name>faultCode</name>', '&lt;here&gt;')
            ->not->toContain('<params>', 'errorMessage', 'success')
            ->and(XmlRpc::answerOf($fault))->toBe(['faultCode' => 404, 'faultString' => 'query.php is not provided here <here>']);
    });
});
