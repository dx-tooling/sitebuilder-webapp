<?php

declare(strict_types=1);

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class            => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class             => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class                      => ['all' => true],
    Symfony\UX\StimulusBundle\StimulusBundle::class                  => ['all' => true],
    Twig\Extra\TwigExtraBundle\TwigExtraBundle::class                => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class              => ['all' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class                => ['all' => true],
    Symfony\UX\TwigComponent\TwigComponentBundle::class              => ['all' => true],
    Symfony\UX\LiveComponent\LiveComponentBundle::class              => ['all' => true],
    Symfonycasts\TailwindBundle\SymfonycastsTailwindBundle::class    => ['all' => true],
    Sensiolabs\TypeScriptBundle\SensiolabsTypeScriptBundle::class    => ['all' => true],
    Sensiolabs\MinifyBundle\SensiolabsMinifyBundle::class            => ['all' => true],
    EnterpriseToolingForSymfony\SharedBundle\SharedBundle::class     => ['all' => true],
    EnterpriseToolingForSymfony\WebuiBundle\WebuiBundle::class       => ['all' => true],
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class        => ['dev' => true, 'test' => true],
    Symfony\Bundle\MakerBundle\MakerBundle::class                    => ['dev' => true],
    Symfony\Bundle\DebugBundle\DebugBundle::class                    => ['dev' => true],
];
