<?php
declare(strict_types=1);
// Models statement -> connection ownership without connecting to any database.
final class FakeConnection {}
final class FakeStatement { public function __construct(public FakeConnection $connection) {} }
$connection = new FakeConnection();
$weak = WeakReference::create($connection);
$guard = new FakeStatement($connection);
$check = new FakeStatement($connection);
$connection = null;
if ($weak->get() === null) { throw new RuntimeException('Missing retained connection control'); }
$guard = null;
if ($weak->get() === null) { throw new RuntimeException('Second statement should retain connection'); }
$check = null;
if ($weak->get() !== null) { throw new RuntimeException('Connection remained referenced'); }
echo "Synthetic reference lifetime passed\n";
