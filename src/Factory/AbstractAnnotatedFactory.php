<?php
namespace Acelaya\ZsmAnnotatedServices\Factory;

use Acelaya\ZsmAnnotatedServices\Annotation\Inject;
use Acelaya\ZsmAnnotatedServices\Exception\RuntimeException;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Cache\Cache;
use Zend\ServiceManager\ServiceLocatorInterface;

abstract class AbstractAnnotatedFactory
{
    const CACHE_SERVICE = 'Acelaya\ZsmAnnotatedServices\Cache';

    /**
     * @var Reader
     */
    private static $annotationReader;

    protected function processDependenciesFromAnnotations(ServiceLocatorInterface $container, $serviceName)
    {
        if (! class_exists($serviceName)) {
            throw new RuntimeException(sprintf(
                'Annotated factories can only be used with services that are identified by their FQCN. ' .
                'Provided "%s" service name is not a valid class.',
                $serviceName
            ));
        }

        $annotationReader = $this->createAnnotationReader($container);
        $refClass = new \ReflectionClass($serviceName);
        $constructor = $refClass->getConstructor();
        if (! isset($constructor)) {
            return new $serviceName();
        }

        /** @var Inject $inject */
        $inject = $annotationReader->getMethodAnnotation($constructor, Inject::class);
        if (! isset($inject)) {
            throw new RuntimeException(sprintf(
                'You need to use the "%s" annotation in "%s" constructor so that the "%s" can create it.',
                Inject::class,
                $serviceName,
                static::class
            ));
        }

        $services = [];
        foreach ($inject->getServices() as $serviceKey) {
            $parts = explode('.', $serviceKey);
            $serviceKey = array_shift($parts);

            if (! $container->has($serviceKey)) {
                throw new RuntimeException(sprintf(
                    'Defined injectable service "%s" could not be found in container.',
                    $serviceKey
                ));
            }

            $service = $container->get($serviceKey);
            $services[] = empty($parts) ? $service : $this->readKeysFromArray($parts, $service);
        }

        // TODO use array unpacking instead of reflection when dropping PHP 5.5 support
        // return new $serviceName(...$services);
        return $refClass->newInstanceArgs($services);
    }

    /**
     * @param ServiceLocatorInterface $container
     * @return AnnotationReader|CachedReader
     */
    private function createAnnotationReader(ServiceLocatorInterface $container)
    {
        if (isset(self::$annotationReader)) {
            return self::$annotationReader;
        }

        AnnotationRegistry::registerLoader(function ($class) {
            $file = str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
            $file = realpath(__DIR__ . '/../Annotation/' . basename($file));
            if (! $file) {
                return false;
            }

            require_once $file;
            return true;
        });

        if (! $container->has(self::CACHE_SERVICE)) {
            return self::$annotationReader = new AnnotationReader();
        } else {
            /** @var Cache $cache */
            $cache = $container->get(self::CACHE_SERVICE);
            return self::$annotationReader = new CachedReader(new AnnotationReader(), $cache);
        }
    }

    /**
     * @param array $keys
     * @param array $array
     * @return mixed|null
     */
    private function readKeysFromArray(array $keys, array $array)
    {
        $key = array_shift($keys);
        $value = isset($array[$key]) ? $array[$key] : null;
        if (! empty($keys) && is_array($value)) {
            $value = $this->readKeysFromArray($keys, $value);
        }

        return $value;
    }
}
