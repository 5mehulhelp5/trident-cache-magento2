<?php

declare(strict_types=1);

namespace Qoliber\TridentCache\Test\Integration\Controller\Warmer;

use PHPUnit\Framework\TestCase;
use Qoliber\TridentCache\Controller\Adminhtml\Warmer\Cancel;
use Qoliber\TridentCache\Controller\Adminhtml\Warmer\Index;
use Qoliber\TridentCache\Controller\Adminhtml\Warmer\Run;

class IndexTest extends TestCase
{
    public function testIndexAdminResource(): void
    {
        $this->assertSame('Qoliber_TridentCache::warmer', Index::ADMIN_RESOURCE);
    }

    public function testRunAdminResource(): void
    {
        $this->assertSame('Qoliber_TridentCache::warmer', Run::ADMIN_RESOURCE);
    }

    public function testCancelAdminResource(): void
    {
        $this->assertSame('Qoliber_TridentCache::warmer', Cancel::ADMIN_RESOURCE);
    }

    public function testControllersExtendBackendAction(): void
    {
        $this->assertTrue(
            is_subclass_of(Index::class, \Magento\Backend\App\Action::class)
        );
        $this->assertTrue(
            is_subclass_of(Run::class, \Magento\Backend\App\Action::class)
        );
        $this->assertTrue(
            is_subclass_of(Cancel::class, \Magento\Backend\App\Action::class)
        );
    }
}
