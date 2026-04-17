<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260104152522 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');
        $this->addSql('CREATE TABLE document_chunk (id SERIAL NOT NULL, analysis_id INT NOT NULL, content TEXT NOT NULL, metadata JSON DEFAULT NULL, embedding vector(1536), PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_FCA7075C7941003F ON document_chunk (analysis_id)');
        $this->addSql('ALTER TABLE document_chunk ADD CONSTRAINT FK_FCA7075C7941003F FOREIGN KEY (analysis_id) REFERENCES analysis (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE document_chunk DROP CONSTRAINT FK_FCA7075C7941003F');
        $this->addSql('DROP TABLE document_chunk');
    }
}
