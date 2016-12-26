<?php //-->
/**
 * This file is part of the Salaaap Project.
 * (c) 2016-2018 Openovate Labs
 *
 * Copyright and license information can be found at LICENSE.txt
 * distributed with this package.
 */

use Cradle\Module\Oauth\Auth\Service;

use Cradle\Module\Oauth\Auth\Service\SqlService;
use Cradle\Module\Oauth\Auth\Service\RedisService;
use Cradle\Module\Oauth\Auth\Service\ElasticService;

/**
 * Service layer test
 *
 * @vendor   Salaaap
 * @package  OAuth
 * @author   Christian Blanquera <cblanquera@openovate.com>
 */
class Cradle_Module_Oauth_Auth_ServiceTest extends PHPUnit_Framework_TestCase
{
    /**
     * @covers Cradle\Module\Oauth\Auth\Service::get
     */
    public function testGet()
    {
        $this->assertInstanceOf(SqlService::class, Service::get('sql'));
        $this->assertInstanceOf(RedisService::class, Service::get('redis'));
        $this->assertInstanceOf(ElasticService::class, Service::get('elastic'));
    }
}