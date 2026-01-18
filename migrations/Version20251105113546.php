<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251105113546 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE app_notifications (
              id CHAR(36) NOT NULL,
              created_at DATETIME NOT NULL,
              message VARCHAR(1024) NOT NULL,
              url VARCHAR(1024) NOT NULL,
              type SMALLINT UNSIGNED NOT NULL,
              is_read TINYINT(1) NOT NULL,
              INDEX created_at_is_read_idx (created_at, is_read),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE etfs_shared_bundle_command_run_summaries (
              id CHAR(36) NOT NULL,
              command_name VARCHAR(512) NOT NULL,
              arguments VARCHAR(1024) NOT NULL,
              options VARCHAR(1024) NOT NULL,
              hostname VARCHAR(1024) NOT NULL,
              envvars VARCHAR(8192) NOT NULL,
              started_at DATETIME NOT NULL,
              finished_at DATETIME DEFAULT NULL,
              finished_due_to_no_initial_lock TINYINT(1) NOT NULL,
              finished_due_to_got_behind_lock TINYINT(1) NOT NULL,
              finished_due_to_failed_to_update_lock TINYINT(1) NOT NULL,
              finished_due_to_rollout_signal TINYINT(1) NOT NULL,
              finished_normally TINYINT(1) NOT NULL,
              number_of_handled_elements INT NOT NULL,
              max_allocated_memory INT NOT NULL,
              INDEX command_name_started_at_idx (command_name, started_at),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE etfs_shared_bundle_signals (
              name VARCHAR(64) NOT NULL,
              created_at DATETIME NOT NULL,
              PRIMARY KEY (name)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
              id BIGINT AUTO_INCREMENT NOT NULL,
              body LONGTEXT NOT NULL,
              headers LONGTEXT NOT NULL,
              queue_name VARCHAR(190) NOT NULL,
              created_at DATETIME NOT NULL,
              available_at DATETIME NOT NULL,
              delivered_at DATETIME DEFAULT NULL,
              INDEX IDX_75EA56E0FB7336F0 (queue_name),
              INDEX IDX_75EA56E0E3BD61CE (available_at),
              INDEX IDX_75EA56E016BA31DB (delivered_at),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE sessions (
              sess_id VARBINARY(128) NOT NULL,
              sess_data LONGBLOB NOT NULL,
              sess_lifetime INT UNSIGNED NOT NULL,
              sess_time INT UNSIGNED NOT NULL,
              INDEX sess_lifetime_idx (sess_lifetime),
              PRIMARY KEY (sess_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE lock_keys (
              key_id VARCHAR(64) NOT NULL,
              key_token VARCHAR(44) NOT NULL,
              key_expiration INT UNSIGNED NOT NULL,
              PRIMARY KEY (key_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_notifications');
        $this->addSql('DROP TABLE etfs_shared_bundle_command_run_summaries');
        $this->addSql('DROP TABLE etfs_shared_bundle_signals');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('DROP TABLE sessions');
        $this->addSql('DROP TABLE lock_keys');
    }
}
