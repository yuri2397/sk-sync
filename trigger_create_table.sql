-- Tables tampons pour la synchronisation avec l'app recouvrement
-- À exécuter dans votre base MSSQL Sage 100

-- Table tampon pour les clients
CREATE TABLE anonymes_customers (
    id INT IDENTITY (1, 1) PRIMARY KEY,
    sage_id NVARCHAR (50) NOT NULL,
    code NVARCHAR (50),
    company_name NVARCHAR (255),
    contact_name NVARCHAR (255),
    email NVARCHAR (255),
    phone NVARCHAR (50),
    address NVARCHAR (MAX),
    payment_delay INT DEFAULT 30,
    currency NVARCHAR (3) DEFAULT 'XOF',
    credit_limit DECIMAL(15, 2),
    max_days_overdue INT DEFAULT 30,
    risk_level NVARCHAR (20) DEFAULT 'low',
    notes NVARCHAR (MAX),
    is_active BIT DEFAULT 1,
    synced BIT DEFAULT 0,
    sync_date DATETIME2,
    created_at DATETIME2 DEFAULT GETDATE (),
    updated_at DATETIME2 DEFAULT GETDATE ()
);

-- Table tampon pour les factures
CREATE TABLE anonymes_invoices (
    id INT IDENTITY (1, 1) PRIMARY KEY,
    sage_id NVARCHAR (50) NOT NULL,
    invoice_number NVARCHAR (100) NOT NULL,
    customer_sage_id NVARCHAR (50) NOT NULL,
    reference NVARCHAR (255),
    type NVARCHAR (50) DEFAULT 'invoice',
    invoice_date DATE NOT NULL,
    currency NVARCHAR (3) DEFAULT 'XOF',
    total_amount DECIMAL(15, 2) NOT NULL,
    notes NVARCHAR (MAX),
    created_by NVARCHAR (255),
    synced BIT DEFAULT 0,
    sync_date DATETIME2,
    created_at DATETIME2 DEFAULT GETDATE (),
    updated_at DATETIME2 DEFAULT GETDATE ()
);

-- Index pour optimiser les performances
CREATE INDEX IX_anonymes_customers_sage_id ON anonymes_customers (sage_id);

CREATE INDEX IX_anonymes_customers_synced ON anonymes_customers (synced);

CREATE INDEX IX_anonymes_invoices_sage_id ON anonymes_invoices (sage_id);

CREATE INDEX IX_anonymes_invoices_synced ON anonymes_invoices (synced);

CREATE INDEX IX_anonymes_invoices_customer ON anonymes_invoices (customer_sage_id);