<?php

namespace Kutny\AutowiringBundle;

use Kutny\AutowiringBundle\Compiler\ClassConstructorFiller;
use Kutny\AutowiringBundle\Compiler\ClassListBuilder;
use ReflectionClass;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

class AutowiringCompilerPass implements CompilerPassInterface
{

    private $classConstructorFiller;
    private $classListBuilder;

    public function __construct(ClassConstructorFiller $classConstructorFiller, ClassListBuilder $classListBuilder)
    {
        $this->classConstructorFiller = $classConstructorFiller;
        $this->classListBuilder = $classListBuilder;
    }

    public function process(ContainerBuilder $containerBuilder)
    {
        $classList = $this->classListBuilder->buildClassList($containerBuilder);
        $serviceDefinitions = $containerBuilder->getDefinitions();

        $ignoredServicesRegExp = $this->getIgnoredServicesRegExp($containerBuilder);
        $forcedWires = $containerBuilder->getParameter('kutny_autowiring.forced_wires');
        foreach ($serviceDefinitions as $serviceId => $definition) {
            if ($definition->isAbstract() || !$definition->isPublic()) {
                continue;
            }

            if ($definition->getClass() === null) {
                continue;
            }

            if ($ignoredServicesRegExp && preg_match($ignoredServicesRegExp, $serviceId)) {
                continue;
            }

            if ($definition->getFactoryClass() || $definition->getFactoryMethod() || (method_exists($definition, 'getFactory') && $definition->getFactory())) {
                continue;
            }

            $this->watchServiceClassForChanges($definition, $containerBuilder);

            $reflection = new ReflectionClass($definition->getClass());
            $constructor = $reflection->getConstructor();

            if ($constructor !== null && $constructor->isPublic()) {
                $this->classConstructorFiller->autowireParams($constructor, $serviceId, $definition, $classList, $forcedWires);
            }
        }
    }

    private function getIgnoredServicesRegExp(ContainerBuilder $containerBuilder)
    {
        $ignoredServices = $containerBuilder->getParameter('kutny_autowiring.ignored_services');

        if (empty($ignoredServices)) {
            return null;
        }

        return '~^(' . implode('|', $ignoredServices) . ')$~';
    }

    private function watchServiceClassForChanges(Definition $definition, ContainerBuilder $containerBuilder)
    {
        $classReflection = new ReflectionClass($definition->getClass());

        do {
            $containerBuilder->addResource(new FileResource($classReflection->getFileName()));
        } while ($classReflection = $classReflection->getParentClass());
    }
}
