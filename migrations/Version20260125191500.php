<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260125191500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mutualisation des documents et fragments entre les analyses';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document (id SERIAL NOT NULL, gpu_doc_id VARCHAR(255) NOT NULL, file_name VARCHAR(255) NOT NULL, update_date VARCHAR(255) DEFAULT NULL, title VARCHAR(255) DEFAULT NULL, url TEXT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE analysis_document (analysis_id INT NOT NULL, document_id INT NOT NULL, PRIMARY KEY(analysis_id, document_id))');
        $this->addSql('CREATE INDEX IDX_8E91AA4491CC992 ON analysis_document (analysis_id)');
        $this->addSql('CREATE INDEX IDX_8E91AA4C33F7837 ON analysis_document (document_id)');
        $this->addSql('ALTER TABLE analysis_document ADD CONSTRAINT FK_8E91AA4491CC992 FOREIGN KEY (analysis_id) REFERENCES analysis (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE analysis_document ADD CONSTRAINT FK_8E91AA4C33F7837 FOREIGN KEY (document_id) REFERENCES document (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE document_chunk ADD document_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE document_chunk ALTER analysis_id DROP NOT NULL');
        $this->addSql('ALTER TABLE document_chunk ADD CONSTRAINT FK_FCA7075CC33F7837 FOREIGN KEY (document_id) REFERENCES document (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_FCA7075CC33F7837 ON document_chunk (document_id)');

        // On vide les tables pour repartir proprement avec le nouveau système
        $this->addSql('DELETE FROM document_chunk');
        $this->addSql('DELETE FROM analysis');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE analysis_document DROP CONSTRAINT FK_8E91AA4491CC992');
        $this->addSql('ALTER TABLE analysis_document DROP CONSTRAINT FK_8E91AA4C33F7837');
        $this->addSql('ALTER TABLE document_chunk DROP CONSTRAINT FK_FCA7075CC33F7837');
        $this->addSql('DROP TABLE document');
        $this->addSql('DROP TABLE analysis_document');
        $this->addSql('DROP INDEX IDX_FCA7075CC33F7837');
        $this->addSql('ALTER TABLE document_chunk DROP COLUMN document_id');
        $this->addSql('ALTER TABLE document_chunk ALTER analysis_id SET NOT NULL');
    }
}
