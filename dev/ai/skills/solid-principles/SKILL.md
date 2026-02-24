---
name: solid-principles
description: SOLID principles for Weline Framework development. Use when designing classes, refactoring code, or reviewing architecture. Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion. 设计原则, refactor, architecture.
globs:
  - "**/*.php"
alwaysApply: false
---

# SOLID Principles in Weline Framework

**⚠️ CRITICAL**: This project MUST strictly follow SOLID principles. This is the foundation of all development standards.

## Overview

SOLID is an acronym for five object-oriented programming and design principles that make software design more understandable, flexible, and maintainable.

## 1. Single Responsibility Principle (SRP)

**Definition**: A class should have only one reason to change. A class should be responsible for only one functional area.

### ❌ Wrong: Multiple Responsibilities

```php
class UserManager
{
    public function createUser() { /* User creation */ }
    public function sendEmail() { /* Email sending */ }
    public function generateReport() { /* Report generation */ }
    public function processPayment() { /* Payment processing */ }
}
```

### ✅ Correct: Single Responsibility

```php
class UserManager
{
    public function createUser() { /* Only user creation */ }
}

class EmailService
{
    public function sendEmail() { /* Only email sending */ }
}

class ReportGenerator
{
    public function generateReport() { /* Only report generation */ }
}

class PaymentProcessor
{
    public function processPayment() { /* Only payment processing */ }
}
```

### Real Examples

- `Processer` class: Only process management
- `Env` class: Only configuration management
- Each Service class: Only specific business logic

## 2. Open-Closed Principle (OCP)

**Definition**: Software entities should be open for extension but closed for modification.

### ❌ Wrong: Modify Existing Code

```php
class PaymentProcessor
{
    public function process($type, $amount)
    {
        if ($type === 'alipay') {
            // Handle Alipay
        } elseif ($type === 'wechat') {
            // Handle WeChat
        }
        // Adding new payment method requires modifying here
    }
}
```

### ✅ Correct: Extend via Interface

```php
interface PaymentInterface
{
    public function process($amount);
}

class AlipayProcessor implements PaymentInterface
{
    public function process($amount) { /* Alipay implementation */ }
}

class WechatProcessor implements PaymentInterface
{
    public function process($amount) { /* WeChat implementation */ }
}

class PaymentProcessor
{
    public function process(PaymentInterface $payment, $amount)
    {
        $payment->process($amount); // No modification needed, just add new implementation
    }
}
```

### Real Examples

- Database driver system: Extend via interface
- Event system: Extend via observer pattern
- Tag system: Extend via interface

## 3. Liskov Substitution Principle (LSP)

**Definition**: Subclass objects should be able to replace parent class objects without breaking program correctness.

### ❌ Wrong: Changing Parent Behavior

```php
class Bird
{
    public function fly() { /* Fly */ }
}

class Penguin extends Bird
{
    public function fly() 
    {
        throw new Exception('Penguins cannot fly'); // Violates LSP
    }
}
```

### ✅ Correct: Proper Inheritance

```php
interface Flyable
{
    public function fly();
}

class Bird implements Flyable
{
    public function fly() { /* Fly */ }
}

class Penguin extends Bird
{
    // Does not implement Flyable - penguins are not flyable birds
}
```

### Real Examples

- All Model classes can replace `Model` base class
- All Controller classes can replace `Controller` base class
- All Service classes follow same interface contract

## 4. Interface Segregation Principle (ISP)

**Definition**: Clients should not depend on interfaces they don't use. Split large interfaces into smaller, more specific ones.

### ❌ Wrong: Large Interface

```php
interface WorkerInterface
{
    public function work();
    public function eat();
    public function sleep();
    public function code();
    public function design();
}

class Developer implements WorkerInterface
{
    public function work() { }
    public function eat() { }
    public function sleep() { }
    public function code() { }
    public function design() { /* Must implement but don't need */ }
}
```

### ✅ Correct: Segregated Interfaces

```php
interface Workable { public function work(); }
interface Eatable { public function eat(); }
interface Sleepable { public function sleep(); }
interface Codeable { public function code(); }

class Developer implements Workable, Eatable, Sleepable, Codeable
{
    public function work() { }
    public function eat() { }
    public function sleep() { }
    public function code() { }
    // Don't need to implement design()
}
```

## 5. Dependency Inversion Principle (DIP)

**Definition**: High-level modules should not depend on low-level modules. Both should depend on abstractions.

### ❌ Wrong: Direct Dependency

```php
class UserService
{
    private $dbConnection; // Depends on concrete implementation
    
    public function __construct()
    {
        $this->dbConnection = new MySQLConnection(); // Tight coupling
    }
}
```

### ✅ Correct: Depend on Abstraction

```php
interface DatabaseInterface
{
    public function query($sql);
}

class MySQLConnection implements DatabaseInterface
{
    public function query($sql) { /* MySQL implementation */ }
}

class UserService
{
    private $db; // Depends on abstraction
    
    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db; // Loose coupling
    }
}
```

### Real Examples

- ObjectManager: Dependency injection container
- Service classes: Depend on interfaces, not implementations
- Event system: Depend on event interfaces

## Application Checklist

When designing or reviewing code:

- [ ] Each class has single responsibility
- [ ] New features extend via interfaces, not modify existing code
- [ ] Subclasses can replace parent classes
- [ ] Interfaces are specific and focused
- [ ] Dependencies are on abstractions, not concretions
- [ ] Code follows framework patterns
- [ ] No violation of any SOLID principle

## Reference

- Development Documentation: `docs/dev/开发文档.md` (Section: 开发原则)
- Framework Architecture: Follow existing framework patterns
