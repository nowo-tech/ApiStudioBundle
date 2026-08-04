<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\ApiStudioBundle\ApiStudioBundle;
use Nowo\TwigInspectorBundle\NowoTwigInspectorBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;

return [
    FrameworkBundle::class         => ['all' => true],
    TwigBundle::class              => ['all' => true],
    WebProfilerBundle::class       => ['dev' => true, 'test' => true],
    DoctrineBundle::class          => ['all' => true],
    SecurityBundle::class          => ['all' => true],
    ApiStudioBundle::class         => ['all' => true],
    NowoUiKitBundle::class         => ['all' => true],
    NowoTwigInspectorBundle::class => ['dev' => true, 'test' => true],
];
