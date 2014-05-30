<?php

/**
 *  [
 *      $context = [
 *          $parameterName => $ParameterProviderClass
 *      ]
 * ];
 */
return [
    'prod' => [
        'now' => '\Demo\Sandbox\Params\CurrentTime'
    ],
    'test' => [
        'now' => '\Demo\Sandbox\Params\FakeTime'
    ],
    'dev' => [],
    'api' => [],
    'stub' => [],
];
