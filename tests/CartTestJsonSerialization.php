<?php

/**
 * Tests to verify cart functionality works correctly with JSON session serialization.
 *
 * Laravel 11+ defaults to 'serialization' => 'json' in config/session.php.
 * This means all session data goes through json_encode/json_decode, converting
 * typed objects (ItemCollection, CartCondition, ItemAttributeCollection) into
 * plain arrays. These tests verify that the cart correctly rehydrates those
 * plain arrays back into their proper types.
 */

use Darryldecode\Cart\Cart;
use Darryldecode\Cart\CartCondition;
use Darryldecode\Cart\ItemCollection;
use Mockery as m;

require_once __DIR__ . '/helpers/JsonSerializingSessionMock.php';

class CartTestJsonSerialization extends PHPUnit\Framework\TestCase
{
    /**
     * @var Cart
     */
    protected $cart;

    public function setUp(): void
    {
        $events = m::mock('Illuminate\Contracts\Events\Dispatcher');
        $events->shouldReceive('dispatch');

        $this->cart = new Cart(
            new JsonSerializingSessionMock(),
            $events,
            'shopping',
            'SAMPLESESSIONKEY',
            require(__DIR__ . '/helpers/configMock.php')
        );
    }

    public function tearDown(): void
    {
        m::close();
    }

    // -------------------------------------------------------
    // Basic item persistence through JSON round-trip
    // -------------------------------------------------------

    public function test_cart_can_add_and_retrieve_item_after_json_serialization()
    {
        $this->cart->add(455, 'Sample Item', 100.99, 2, array());

        $this->assertFalse($this->cart->isEmpty(), 'Cart should not be empty');
        $this->assertEquals(1, $this->cart->getContent()->count(), 'Cart content should be 1');

        $item = $this->cart->getContent()->first();
        $this->assertInstanceOf(ItemCollection::class, $item, 'Item should be rehydrated into ItemCollection');
        $this->assertEquals(455, $item['id']);
        $this->assertEquals('Sample Item', $item['name']);
        $this->assertEquals(100.99, $item['price']);
        $this->assertEquals(2, $item['quantity']);
    }

    public function test_cart_can_add_item_as_array_after_json_serialization()
    {
        $item = array(
            'id' => 456,
            'name' => 'Sample Item',
            'price' => 67.99,
            'quantity' => 4,
            'attributes' => array()
        );

        $this->cart->add($item);

        $this->assertFalse($this->cart->isEmpty(), 'Cart should not be empty');
        $this->assertEquals(1, $this->cart->getContent()->count(), 'Cart should have 1 item');
        $this->assertEquals(456, $this->cart->getContent()->first()['id']);
        $this->assertEquals('Sample Item', $this->cart->getContent()->first()['name']);
    }

    public function test_cart_can_add_multiple_items_after_json_serialization()
    {
        $items = array(
            array(
                'id' => 456,
                'name' => 'Sample Item 1',
                'price' => 67.99,
                'quantity' => 4,
                'attributes' => array()
            ),
            array(
                'id' => 568,
                'name' => 'Sample Item 2',
                'price' => 69.25,
                'quantity' => 4,
                'attributes' => array()
            ),
            array(
                'id' => 856,
                'name' => 'Sample Item 3',
                'price' => 50.25,
                'quantity' => 4,
                'attributes' => array()
            ),
        );

        $this->cart->add($items);

        $this->assertFalse($this->cart->isEmpty(), 'Cart should not be empty');
        $this->assertEquals(3, $this->cart->getContent()->count(), 'Cart should have 3 items');
    }

    // -------------------------------------------------------
    // Item attributes survive JSON round-trip
    // -------------------------------------------------------

    public function test_item_attributes_survive_json_serialization()
    {
        $this->cart->add(455, 'Sample Item', 100.99, 2, array(
            'size' => 'L',
            'color' => 'blue',
        ));

        $item = $this->cart->getContent()->first();
        $this->assertInstanceOf(ItemCollection::class, $item);
        $this->assertEquals('L', $item->attributes->size);
        $this->assertEquals('blue', $item->attributes->color);
    }

    // -------------------------------------------------------
    // Item price calculations work after JSON round-trip
    // -------------------------------------------------------

    public function test_price_sum_works_after_json_serialization()
    {
        $this->cart->add(455, 'Sample Item', 100.99, 2, array());

        $item = $this->cart->getContent()->first();
        $this->assertEquals(201.98, $item->getPriceSum(), 'Price sum should be 100.99 * 2 = 201.98');
    }

    public function test_cart_sub_total_works_after_json_serialization()
    {
        $this->cart->add(455, 'Sample Item 1', 100.00, 2, array());
        $this->cart->add(456, 'Sample Item 2', 200.00, 1, array());

        $this->assertEquals(400.00, $this->cart->getSubTotal(false), 'SubTotal should be (100*2) + (200*1) = 400');
    }

    public function test_cart_total_quantity_works_after_json_serialization()
    {
        $this->cart->add(455, 'Sample Item 1', 100.00, 2, array());
        $this->cart->add(456, 'Sample Item 2', 200.00, 3, array());

        $this->assertEquals(5, $this->cart->getTotalQuantity(), 'Total quantity should be 2 + 3 = 5');
    }

    // -------------------------------------------------------
    // Item-level conditions survive JSON round-trip
    // -------------------------------------------------------

    public function test_item_with_single_condition_survives_json_serialization()
    {
        $itemCondition = new CartCondition(array(
            'name' => 'Item Sale 5%',
            'type' => 'sale',
            'value' => '-5%',
        ));

        $this->cart->add(455, 'Sample Item', 100.00, 1, array(), $itemCondition);

        $item = $this->cart->getContent()->first();
        $this->assertInstanceOf(ItemCollection::class, $item);

        // The item should have conditions and be able to calculate price with them
        $this->assertTrue($item->hasConditions(), 'Item should have conditions after JSON round-trip');
        $this->assertEquals(95.00, $item->getPriceWithConditions(false), 'Price with -5% condition should be 95.00');
    }

    public function test_item_with_multiple_conditions_survives_json_serialization()
    {
        $itemCondition1 = new CartCondition(array(
            'name' => 'Item Sale 5%',
            'type' => 'sale',
            'value' => '-5%',
        ));

        $itemCondition2 = new CartCondition(array(
            'name' => 'Item Sale 10%',
            'type' => 'sale',
            'value' => '-10%',
        ));

        $this->cart->add(455, 'Sample Item', 100.00, 1, array(), [$itemCondition1, $itemCondition2]);

        $item = $this->cart->getContent()->first();
        $this->assertInstanceOf(ItemCollection::class, $item);
        $this->assertTrue($item->hasConditions(), 'Item should have conditions after JSON round-trip');

        // First -5%: 100 * 0.95 = 95, then -10%: 95 * 0.90 = 85.5
        $this->assertEquals(85.5, $item->getPriceWithConditions(false), 'Price with two discounts should be 85.5');
    }

    // -------------------------------------------------------
    // Cart-level conditions survive JSON round-trip
    // -------------------------------------------------------

    public function test_cart_condition_survives_json_serialization()
    {
        $cartCondition = new CartCondition(array(
            'name' => 'Express Shipping',
            'type' => 'shipping',
            'target' => 'total',
            'value' => '+15',
        ));

        $this->cart->condition($cartCondition);
        $this->cart->add(455, 'Sample Item', 100.00, 1, array());

        $conditions = $this->cart->getConditions();
        $this->assertEquals(1, $conditions->count(), 'Should have 1 cart condition');

        $condition = $this->cart->getCondition('Express Shipping');
        $this->assertInstanceOf(CartCondition::class, $condition, 'Condition should be rehydrated to CartCondition');
        $this->assertEquals('Express Shipping', $condition->getName());
        $this->assertEquals('shipping', $condition->getType());
        $this->assertEquals('total', $condition->getTarget());
        $this->assertEquals('+15', $condition->getValue());
    }

    public function test_cart_total_with_conditions_after_json_serialization()
    {
        $cartCondition = new CartCondition(array(
            'name' => 'Express Shipping',
            'type' => 'shipping',
            'target' => 'total',
            'value' => '+15',
        ));

        $this->cart->condition($cartCondition);
        $this->cart->add(455, 'Sample Item', 100.00, 1, array());

        $this->assertEquals(115.00, $this->cart->getTotal(), 'Total should be 100 + 15 = 115');
    }

    public function test_cart_subtotal_with_conditions_after_json_serialization()
    {
        $subtotalCondition = new CartCondition(array(
            'name' => 'Subtotal Discount 10%',
            'type' => 'discount',
            'target' => 'subtotal',
            'value' => '-10%',
        ));

        $this->cart->condition($subtotalCondition);
        $this->cart->add(455, 'Sample Item', 100.00, 2, array());

        // Subtotal without conditions: 100 * 2 = 200
        // Subtotal with -10% condition: 200 - 20 = 180
        $this->assertEquals(180.00, $this->cart->getSubTotal(false), 'SubTotal with -10% should be 180');
    }

    public function test_multiple_cart_conditions_survive_json_serialization()
    {
        $condition1 = new CartCondition(array(
            'name' => 'Express Shipping',
            'type' => 'shipping',
            'target' => 'total',
            'value' => '+15',
            'order' => 1,
        ));

        $condition2 = new CartCondition(array(
            'name' => 'Tax 10%',
            'type' => 'tax',
            'target' => 'total',
            'value' => '+10%',
            'order' => 2,
        ));

        $this->cart->condition($condition1);
        $this->cart->condition($condition2);
        $this->cart->add(455, 'Sample Item', 100.00, 1, array());

        $conditions = $this->cart->getConditions();
        $this->assertEquals(2, $conditions->count(), 'Should have 2 cart conditions');
    }

    // -------------------------------------------------------
    // Cart operations work after JSON round-trip
    // -------------------------------------------------------

    public function test_cart_update_works_after_json_serialization()
    {
        $this->cart->add(455, 'Sample Item', 100.00, 2, array());

        $this->cart->update(455, array(
            'name' => 'Updated Item',
            'price' => 150.00,
        ));

        $item = $this->cart->get(455);
        $this->assertInstanceOf(ItemCollection::class, $item);
        $this->assertEquals('Updated Item', $item['name']);
        $this->assertEquals(150.00, $item['price']);
    }

    public function test_cart_update_quantity_works_after_json_serialization()
    {
        $this->cart->add(455, 'Sample Item', 100.00, 2, array());

        $this->cart->update(455, array(
            'quantity' => 1,
        ));

        $item = $this->cart->get(455);
        $this->assertEquals(3, $item['quantity'], 'Quantity should be 2 + 1 = 3 (relative update)');
    }

    public function test_cart_remove_works_after_json_serialization()
    {
        $this->cart->add(455, 'Sample Item 1', 100.00, 2, array());
        $this->cart->add(456, 'Sample Item 2', 200.00, 1, array());

        $this->cart->remove(455);

        $this->assertEquals(1, $this->cart->getContent()->count(), 'Cart should have 1 item after removal');
        $this->assertFalse($this->cart->has(455), 'Item 455 should be removed');
        $this->assertTrue($this->cart->has(456), 'Item 456 should still be in cart');
    }

    public function test_cart_clear_works_after_json_serialization()
    {
        $this->cart->add(455, 'Sample Item 1', 100.00, 2, array());
        $this->cart->add(456, 'Sample Item 2', 200.00, 1, array());

        $this->cart->clear();

        $this->assertTrue($this->cart->isEmpty(), 'Cart should be empty after clearing');
        $this->assertEquals(0, $this->cart->getContent()->count(), 'Cart content count should be 0');
    }

    public function test_cart_has_works_after_json_serialization()
    {
        $this->cart->add(455, 'Sample Item', 100.00, 2, array());

        $this->assertTrue($this->cart->has(455), 'Cart should have item 455');
        $this->assertFalse($this->cart->has(999), 'Cart should not have item 999');
    }

    public function test_cart_get_works_after_json_serialization()
    {
        $this->cart->add(455, 'Sample Item', 100.99, 2, array());

        $item = $this->cart->get(455);
        $this->assertInstanceOf(ItemCollection::class, $item);
        $this->assertEquals(455, $item['id']);
        $this->assertEquals('Sample Item', $item['name']);
    }

    // -------------------------------------------------------
    // Condition management works after JSON round-trip
    // -------------------------------------------------------

    public function test_remove_cart_condition_after_json_serialization()
    {
        $condition = new CartCondition(array(
            'name' => 'Express Shipping',
            'type' => 'shipping',
            'target' => 'total',
            'value' => '+15',
        ));

        $this->cart->condition($condition);
        $this->assertEquals(1, $this->cart->getConditions()->count());

        $this->cart->removeCartCondition('Express Shipping');
        $this->assertEquals(0, $this->cart->getConditions()->count(), 'Condition should be removed');
    }

    public function test_remove_item_condition_after_json_serialization()
    {
        $itemCondition1 = new CartCondition(array(
            'name' => 'Sale 5%',
            'type' => 'sale',
            'value' => '-5%',
        ));

        $itemCondition2 = new CartCondition(array(
            'name' => 'Sale 10%',
            'type' => 'sale',
            'value' => '-10%',
        ));

        $this->cart->add(455, 'Sample Item', 100.00, 1, array(), [$itemCondition1, $itemCondition2]);

        $this->cart->removeItemCondition(455, 'Sale 5%');

        $item = $this->cart->get(455);
        $this->assertInstanceOf(ItemCollection::class, $item);

        // After removing 'Sale 5%', only 'Sale 10%' remains
        // Price should be 100 - 10% = 90
        $this->assertEquals(90.00, $item->getPriceWithConditions(false), 'Price with only -10% should be 90');
    }

    public function test_clear_item_conditions_after_json_serialization()
    {
        $itemCondition = new CartCondition(array(
            'name' => 'Sale 5%',
            'type' => 'sale',
            'value' => '-5%',
        ));

        $this->cart->add(455, 'Sample Item', 100.00, 1, array(), $itemCondition);

        $this->cart->clearItemConditions(455);

        $item = $this->cart->get(455);
        $this->assertEquals(100.00, $item->getPriceWithConditions(false), 'Price should be 100 after clearing conditions');
    }

    public function test_add_item_condition_after_json_serialization()
    {
        $this->cart->add(455, 'Sample Item', 100.00, 1, array());

        $itemCondition = new CartCondition(array(
            'name' => 'Sale 5%',
            'type' => 'sale',
            'value' => '-5%',
        ));

        $this->cart->addItemCondition(455, $itemCondition);

        $item = $this->cart->get(455);
        $this->assertEquals(95.00, $item->getPriceWithConditions(false), 'Price with -5% condition should be 95');
    }

    // -------------------------------------------------------
    // Get conditions by type after JSON round-trip
    // -------------------------------------------------------

    public function test_get_conditions_by_type_after_json_serialization()
    {
        $condition1 = new CartCondition(array(
            'name' => 'Express Shipping',
            'type' => 'shipping',
            'target' => 'total',
            'value' => '+15',
        ));

        $condition2 = new CartCondition(array(
            'name' => 'Tax 10%',
            'type' => 'tax',
            'target' => 'total',
            'value' => '+10%',
        ));

        $condition3 = new CartCondition(array(
            'name' => 'Standard Shipping',
            'type' => 'shipping',
            'target' => 'total',
            'value' => '+5',
        ));

        $this->cart->condition([$condition1, $condition2, $condition3]);

        $shippingConditions = $this->cart->getConditionsByType('shipping');
        $this->assertEquals(2, $shippingConditions->count(), 'Should have 2 shipping conditions');

        $taxConditions = $this->cart->getConditionsByType('tax');
        $this->assertEquals(1, $taxConditions->count(), 'Should have 1 tax condition');
    }

    // -------------------------------------------------------
    // Adding duplicate items (update quantity) after JSON round-trip
    // -------------------------------------------------------

    public function test_adding_same_item_updates_quantity_after_json_serialization()
    {
        $this->cart->add(455, 'Sample Item', 100.00, 2, array());
        $this->cart->add(455, 'Sample Item', 100.00, 3, array());

        $this->assertEquals(1, $this->cart->getContent()->count(), 'Cart should have 1 item (same ID)');

        $item = $this->cart->get(455);
        $this->assertEquals(5, $item['quantity'], 'Quantity should be 2 + 3 = 5');
    }

    // -------------------------------------------------------
    // CartCondition::fromArray edge cases
    // -------------------------------------------------------

    public function test_cart_condition_json_serializable()
    {
        $condition = new CartCondition(array(
            'name' => 'Test',
            'type' => 'discount',
            'target' => 'total',
            'value' => '-10%',
        ));

        $json = json_encode($condition);
        $decoded = json_decode($json, true);

        $this->assertEquals('CartCondition', $decoded['__darryldecode_type']);
        $this->assertEquals('Test', $decoded['args']['name']);
        $this->assertEquals('discount', $decoded['args']['type']);
        $this->assertEquals('total', $decoded['args']['target']);
        $this->assertEquals('-10%', $decoded['args']['value']);

        // Verify we can reconstruct from the decoded data
        $reconstructed = CartCondition::fromArray($decoded);
        $this->assertInstanceOf(CartCondition::class, $reconstructed);
        $this->assertEquals('Test', $reconstructed->getName());
        $this->assertEquals('discount', $reconstructed->getType());
        $this->assertEquals('total', $reconstructed->getTarget());
        $this->assertEquals('-10%', $reconstructed->getValue());
    }

    public function test_cart_condition_from_array_with_plain_args()
    {
        // Test reconstruction from a plain args array (no type marker)
        $condition = CartCondition::fromArray([
            'name' => 'Direct Test',
            'type' => 'tax',
            'value' => '+5%',
            'target' => 'subtotal',
        ]);

        $this->assertInstanceOf(CartCondition::class, $condition);
        $this->assertEquals('Direct Test', $condition->getName());
        $this->assertEquals('tax', $condition->getType());
        $this->assertEquals('+5%', $condition->getValue());
    }

    public function test_cart_condition_from_array_returns_null_for_invalid_data()
    {
        $result = CartCondition::fromArray(['foo' => 'bar']);
        $this->assertNull($result);
    }

    // -------------------------------------------------------
    // Complex scenario: full cart workflow through JSON
    // -------------------------------------------------------

    public function test_full_cart_workflow_through_json_serialization()
    {
        // Step 1: Add items
        $this->cart->add(1, 'Laptop', 1500.00, 1, ['brand' => 'Dell']);
        $this->cart->add(2, 'Mouse', 25.00, 2, ['brand' => 'Logitech']);

        // Step 2: Add item-level condition
        $itemDiscount = new CartCondition(array(
            'name' => 'Laptop Discount',
            'type' => 'sale',
            'value' => '-10%',
        ));
        $this->cart->addItemCondition(1, $itemDiscount);

        // Step 3: Add cart-level conditions
        $shipping = new CartCondition(array(
            'name' => 'Shipping',
            'type' => 'shipping',
            'target' => 'total',
            'value' => '+25',
        ));
        $this->cart->condition($shipping);

        // Verify everything works
        $this->assertEquals(2, $this->cart->getContent()->count(), 'Should have 2 items');

        $laptop = $this->cart->get(1);
        $this->assertInstanceOf(ItemCollection::class, $laptop);
        $this->assertEquals('Laptop', $laptop->name);
        $this->assertEquals('Dell', $laptop->attributes->brand);
        $this->assertEquals(1350.00, $laptop->getPriceWithConditions(false), 'Laptop price with -10% = 1350');

        $mouse = $this->cart->get(2);
        $this->assertInstanceOf(ItemCollection::class, $mouse);
        $this->assertEquals('Mouse', $mouse->name);
        $this->assertEquals('Logitech', $mouse->attributes->brand);
        $this->assertEquals(50.00, $mouse->getPriceSum(), 'Mouse price sum = 25 * 2 = 50');

        // Subtotal: 1350 (laptop with condition) + 50 (mouse) = 1400
        $this->assertEquals(1400.00, $this->cart->getSubTotal(false));

        // Total: 1400 + 25 (shipping) = 1425
        $this->assertEquals(1425.00, $this->cart->getTotal());

        $this->assertEquals(3, $this->cart->getTotalQuantity(), 'Total qty: 1 + 2 = 3');

        // Step 4: Update item
        $this->cart->update(2, ['quantity' => 1]);
        $mouse = $this->cart->get(2);
        $this->assertEquals(3, $mouse['quantity'], 'Mouse qty: 2 + 1 = 3');

        // Step 5: Remove item
        $this->cart->remove(2);
        $this->assertEquals(1, $this->cart->getContent()->count(), 'Should have 1 item after removal');

        // Step 6: Clear
        $this->cart->clear();
        $this->assertTrue($this->cart->isEmpty(), 'Cart should be empty');
    }
}
