<?php

declare(strict_types=1);

/**
 * @author      Andreas Knollmann
 * @copyright   Copyright (c) 2014-2026 Softwareentwicklung Andreas Knollmann
 * @license     http://www.opensource.org/licenses/mit-license.php MIT
 */

namespace Infrangible\ImportEntity\Test\Unit\Model;

use Exception;
use FeWeDev\Base\Arrays;
use FeWeDev\Base\Variables;
use Infrangible\Core\Helper\Attribute;
use Infrangible\Core\Helper\Database;
use Infrangible\Core\Helper\EntityType;
use Infrangible\Core\Helper\Export;
use Infrangible\Core\Helper\Instances;
use Infrangible\Core\Helper\Stores;
use Infrangible\Import\Helper\Data;
use Magento\Eav\Model\Entity\Attribute as EavAttribute;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the steps of an entity import that do not need a database.
 */
class EntityTest extends TestCase
{
    /** @var Stores */
    private Stores $storeHelper;

    /** @var Instances */
    private Instances $instanceHelper;

    /** @var Attribute */
    private Attribute $attributeHelper;

    /** @var Data */
    private Data $importHelper;

    /**
     * Prepares a website "base" with the ID 1 and the default store 1.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $website = $this->createStub(Website::class);
        $website->method('getId')->willReturn('1');

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn('1');

        $storeHelper = $this->createStub(Stores::class);
        $storeHelper->method('getWebsite')->willReturnCallback(
            fn(int|string|null $websiteId): Website => in_array($websiteId, [1, 'base'], true) ? $website :
                throw new NoSuchEntityException()
        );
        $storeHelper->method('getDefaultStore')->willReturn($store);

        $this->storeHelper = $storeHelper;
        $this->instanceHelper = $this->createStub(Instances::class);
        $this->attributeHelper = $this->createStub(Attribute::class);
        $this->importHelper = $this->createStub(Data::class);
    }

    /**
     * Returns an entity import with the helpers of the test.
     *
     * @return TestEntity
     */
    private function createEntity(): TestEntity
    {
        $variables = new Variables();

        return new TestEntity(
            new Arrays($variables),
            $variables,
            $this->createStub(Database::class),
            $this->instanceHelper,
            $this->importHelper,
            $this->storeHelper,
            $this->attributeHelper,
            $this->createStub(EntityType::class),
            $this->createStub(Export::class),
            $this->createStub(LoggerInterface::class)
        );
    }

    public function testStoreOfWebsiteIdAsString(): void
    {
        $entity = $this->createEntity();

        $element = $entity->addStore(1, ['sku' => 'A', 'website_id' => '1']);

        $this->assertSame(1, $element['store_id']);
        $this->assertSame([], $entity->getTransformedInvalidElementNumbers());
    }

    public function testStoreOfWebsiteCode(): void
    {
        $entity = $this->createEntity();

        $element = $entity->addStore(1, ['sku' => 'A', 'website' => ['name' => 'base']]);

        $this->assertSame(1, $element['store_id']);
    }

    public function testUnknownWebsiteMakesTheElementInvalid(): void
    {
        $entity = $this->createEntity();

        $element = $entity->addStore(1, ['sku' => 'A', 'website' => ['name' => 'unknown']]);

        $this->assertArrayNotHasKey('store_id', $element);
        $this->assertSame([1], $entity->getTransformedInvalidElementNumbers());
    }

    public function testElementWithoutWebsiteGetsTheAdminStore(): void
    {
        $element = $this->createEntity()->addStore(1, ['sku' => 'A']);

        $this->assertSame(0, $element['store_id']);
    }

    public function testAssociatedDataIsSplitAtPipes(): void
    {
        $associated = new TestAssociated(
            $this->createStub(Database::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(TestEntity::class)
        );

        $instanceHelper = $this->createStub(Instances::class);
        $instanceHelper->method('getInstance')->willReturn($associated);
        $this->instanceHelper = $instanceHelper;

        $entity = $this->createEntity();
        $entity->addAssociatedItemModel('images', TestAssociated::class);

        $element = $entity->addAssociatedModels(
            $this->createStub(AdapterInterface::class),
            ['sku' => 'A', 'images' => 'a.jpg|b.jpg']
        );

        $this->assertSame($associated, $element['images']);
        $this->assertSame(['a.jpg', 'b.jpg'], $associated->data);
    }

    /**
     * @throws Exception
     */
    public function testPrepareAttributeReportsOnlyChangedValues(): void
    {
        $attribute = $this->createStub(EavAttribute::class);
        $attribute->method('usesSource')->willReturn(false);

        $attributeHelper = $this->createStub(Attribute::class);
        $attributeHelper->method('getAttribute')->willReturn($attribute);
        $this->attributeHelper = $attributeHelper;

        $importHelper = $this->createStub(Data::class);
        $importHelper->method('prepareValueForSave')->willReturnArgument(1);
        $importHelper->method('createAttributeUpdates')->willReturn(
            [['type' => 'eav', 'table' => 'catalog_product_entity_varchar', 'data' => ['value' => 'new']]]
        );
        $this->importHelper = $importHelper;

        $entity = $this->createEntity();

        $this->assertFalse(
            $entity->prepareAttribute('catalog_product', 5, 1, 'name', 'old', ['name' => 'old'], [], false)
        );
        $this->assertTrue(
            $entity->prepareAttribute('catalog_product', 5, 1, 'name', 'new', ['name' => 'old'], [], false)
        );
    }
}
