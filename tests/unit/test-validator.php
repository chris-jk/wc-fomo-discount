<?php
/**
 * Test Validator Class
 * 
 * @package WC_Fomo_Discount
 * @subpackage Tests
 */

use WCFD\Core\Logger;
use WCFD\Core\Validator;

class Test_Validator extends WP_UnitTestCase {
    
    /**
     * @var Validator
     */
    private $validator;
    
    /**
     * @var Logger
     */
    private $logger;
    
    /**
     * Set up test
     */
    public function setUp(): void {
        parent::setUp();
        
        $this->logger = new Logger();
        $this->validator = new Validator($this->logger);
    }
    
    /**
     * Test email validation - valid emails
     */
    public function test_validate_email_valid() {
        $valid_emails = [
            'test@example.com',
            'user.name@domain.co.uk',
            'first.last+tag@example.org',
            'user123@test-domain.net'
        ];
        
        foreach ($valid_emails as $email) {
            $this->assertTrue($this->validator->validate_email($email), "Email should be valid: $email");
            $this->assertFalse($this->validator->has_errors(), "No errors should exist for valid email: $email");
            $this->validator->clear_errors();
        }
    }
    
    /**
     * Test email validation - invalid emails
     */
    public function test_validate_email_invalid() {
        $invalid_emails = [
            '',
            'invalid-email',
            '@domain.com',
            'user@',
            'user@domain',
            str_repeat('a', 250) . '@domain.com', // Too long
        ];
        
        foreach ($invalid_emails as $email) {
            $this->assertFalse($this->validator->validate_email($email), "Email should be invalid: $email");
            $this->assertTrue($this->validator->has_errors(), "Errors should exist for invalid email: $email");
            $this->validator->clear_errors();
        }
    }
    
    /**
     * Test campaign ID validation
     */
    public function test_validate_campaign_id() {
        // Valid IDs
        $this->assertEquals(123, $this->validator->validate_campaign_id('123'));
        $this->assertEquals(1, $this->validator->validate_campaign_id(1));
        
        // Invalid IDs
        $this->assertFalse($this->validator->validate_campaign_id(''));
        $this->assertFalse($this->validator->validate_campaign_id('abc'));
        $this->assertFalse($this->validator->validate_campaign_id(-1));
        $this->assertFalse($this->validator->validate_campaign_id(0));
    }
    
    /**
     * Test discount value validation
     */
    public function test_validate_discount_value() {
        // Valid percent discounts
        $this->assertEquals(10.5, $this->validator->validate_discount_value('10.5', 'percent'));
        $this->assertEquals(100, $this->validator->validate_discount_value(100, 'percent'));
        
        // Invalid percent discounts
        $this->assertFalse($this->validator->validate_discount_value(101, 'percent'));
        $this->assertFalse($this->validator->validate_discount_value(-5, 'percent'));
        
        // Valid fixed discounts
        $this->assertEquals(25.99, $this->validator->validate_discount_value('25.99', 'fixed'));
        
        // Invalid fixed discounts
        $this->assertFalse($this->validator->validate_discount_value(-10, 'fixed'));
    }
    
    /**
     * Test IP address validation
     */
    public function test_validate_ip_address() {
        // Valid IPs
        $this->assertEquals('192.168.1.1', $this->validator->validate_ip_address('192.168.1.1'));
        $this->assertEquals('::1', $this->validator->validate_ip_address('::1'));
        
        // Invalid IPs
        $this->assertFalse($this->validator->validate_ip_address('invalid-ip'));
        $this->assertFalse($this->validator->validate_ip_address('999.999.999.999'));
        $this->validator->clear_errors();
    }
    
    /**
     * Test text validation
     */
    public function test_validate_text() {
        // Valid text
        $this->assertEquals('Hello World', $this->validator->validate_text('Hello World'));
        
        // Required field validation
        $this->assertFalse($this->validator->validate_text('', ['required' => true]));
        $this->assertTrue($this->validator->has_errors());
        $this->validator->clear_errors();
        
        // Length validation
        $options = ['min_length' => 5, 'max_length' => 10];
        $this->assertEquals('Hello', $this->validator->validate_text('Hello', $options));
        $this->assertFalse($this->validator->validate_text('Hi', $options)); // Too short
        $this->assertFalse($this->validator->validate_text('This is too long', $options)); // Too long
        $this->validator->clear_errors();
        
        // Pattern validation
        $options = ['pattern' => '/^[A-Z]+$/'];
        $this->assertEquals('HELLO', $this->validator->validate_text('HELLO', $options));
        $this->assertFalse($this->validator->validate_text('hello', $options));
        $this->validator->clear_errors();
    }
    
    /**
     * Test error handling
     */
    public function test_error_handling() {
        // Trigger an error
        $this->validator->validate_email('invalid-email');
        
        // Check error methods
        $this->assertTrue($this->validator->has_errors());
        $this->assertNotEmpty($this->validator->get_errors());
        $this->assertNotEmpty($this->validator->get_error_message());
        
        // Clear errors
        $this->validator->clear_errors();
        $this->assertFalse($this->validator->has_errors());
        $this->assertEmpty($this->validator->get_errors());
    }
}