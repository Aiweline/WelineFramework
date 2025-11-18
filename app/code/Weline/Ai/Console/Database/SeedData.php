<?php

declare(strict_types=1);

namespace Weline\Ai\Console\Database;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ai\Setup\Seed;
use Weline\Framework\Setup\Db\Setup;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Database\ConnectionFactory;

/**
 * Console command to seed test data for AI module
 */
class SeedData implements CommandInterface
{

    public function execute(array $args = [], array $data = [])
    {
        $this->output->writeln('=== Seeding AI Module Test Data ===');
        
        try {
            // Get connection
            $connFactory = ObjectManager::getInstance(ConnectionFactory::class);
            $conn = $connFactory->getConnection();
            
            // Create setup instance
            $setup = new Setup($conn);
            $context = new Context();
            
            // Get seed instance and execute
            $seed = new Seed();
            $seed->seed($setup, $context);
            
            $this->output->success('✅ Test data seeded successfully!');
            
        } catch (\Exception $e) {
            $this->output->error('Error seeding data: ' . $e->getMessage());
            $this->output->writeln($e->getTraceAsString());
            return self::FAIL;
        }
        
        return self::SUCCESS;
    }

    public function tip(): string
    {
        return '为AI模块创建测试数据';
    }
    
    public function help(array $args = [], array $data = []): string
    {
        return '为AI模块创建测试数据。用法: php bin/w ai:database:seed-data';
    }
}

