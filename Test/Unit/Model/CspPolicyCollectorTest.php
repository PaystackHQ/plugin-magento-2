<?php

namespace Pstk\Paystack\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Pstk\Paystack\Model\CspPolicyCollector;
use Magento\Csp\Model\Policy\FetchPolicy;

class CspPolicyCollectorTest extends TestCase
{
    /** @var CspPolicyCollector */
    private $collector;

    protected function setUp(): void
    {
        $this->collector = new CspPolicyCollector();
    }

    public function testCollectAddsPolicies(): void
    {
        $policies = $this->collector->collect([]);

        $this->assertCount(3, $policies);
        $this->assertContainsOnlyInstancesOf(FetchPolicy::class, $policies);
    }

    public function testCollectPreservesDefaultPolicies(): void
    {
        $existing = [new FetchPolicy('default-src', false, ['example.com'])];
        $policies = $this->collector->collect($existing);

        $this->assertCount(4, $policies);
        $this->assertSame($existing[0], $policies[0]);
    }

    public function testScriptSrcPolicy(): void
    {
        $policies = $this->collector->collect();
        $scriptSrc = $policies[0];

        $this->assertEquals('script-src', $scriptSrc->getId());
    }

    public function testConnectSrcPolicy(): void
    {
        $policies = $this->collector->collect();
        $connectSrc = $policies[1];

        $this->assertEquals('connect-src', $connectSrc->getId());
    }

    public function testFrameSrcPolicy(): void
    {
        $policies = $this->collector->collect();
        $frameSrc = $policies[2];

        $this->assertEquals('frame-src', $frameSrc->getId());
    }
}
