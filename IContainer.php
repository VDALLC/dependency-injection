<?php
namespace Vda\DependencyInjection;

interface IContainer
{
    public function get($beanName);
    public function hasBean($beanName);
    public function test(array $beanNames = null);
}
