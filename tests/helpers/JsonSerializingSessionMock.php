<?php

/**
 * A session mock that simulates Laravel's JSON session serialization.
 *
 * When Laravel uses 'serialization' => 'json' (the default since Laravel 11),
 * all session data is json_encode()'d on write and json_decode()'d on read.
 * This means any objects (like ItemCollection, CartCondition, etc.) come back
 * as plain arrays/stdClass objects instead of their original types.
 *
 * This mock replicates that exact behavior so we can test that the cart
 * correctly rehydrates items and conditions from plain arrays.
 */
class JsonSerializingSessionMock
{
    protected $session = array();

    public function has($key)
    {
        return isset($this->session[$key]);
    }

    public function get($key)
    {
        if (!isset($this->session[$key])) {
            return null;
        }

        // Simulate JSON round-trip: decode the stored JSON back into PHP arrays
        return json_decode($this->session[$key], true);
    }

    public function put($key, $value)
    {
        // Simulate JSON round-trip: encode the value to JSON before storing
        $this->session[$key] = json_encode($value);
    }
}
