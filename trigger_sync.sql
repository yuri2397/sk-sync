-- Trigger pour synchroniser automatiquement les clients depuis F_COMPTET
CREATE TRIGGER tr_sync_customers_from_comptet
ON F_COMPTET
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    -- Synchroniser les clients modifiés/ajoutés
    MERGE anonymes_customers AS target
    USING (
        SELECT
            i.CT_Num as sage_id,
            i.CT_Num as code,
            i.CT_Intitule as company_name,
            i.CT_Contact as contact_name,
            i.CT_EMail as email,
            i.CT_Telephone as phone,
            CONCAT(ISNULL(i.CT_Adresse, ''), ' ', ISNULL(i.CT_Complement, ''), ' ', ISNULL(i.CT_CodePostal, ''), ' ', ISNULL(i.CT_Ville, '')) as address,
            30 as payment_delay, -- Valeur par défaut
            'XOF' as currency,
            i.CT_Encours as credit_limit,
            30 as max_days_overdue,
            CASE
                WHEN ISNULL(i.N_Risque, 1) = 1 THEN 'low'
                WHEN i.N_Risque = 2 THEN 'medium'
                WHEN i.N_Risque = 3 THEN 'high'
                ELSE 'low'
            END as risk_level,
            i.CT_Commentaire as notes,
            CASE
                WHEN ISNULL(i.CT_Sommeil, 0) = 0 THEN 1
                ELSE 0
            END as is_active
        FROM inserted i
        WHERE i.CT_Type = 0  -- Clients seulement
    ) AS source ON target.sage_id = source.sage_id

    WHEN MATCHED THEN
        UPDATE SET
            code = source.code,
            company_name = source.company_name,
            contact_name = source.contact_name,
            email = source.email,
            phone = source.phone,
            address = source.address,
            currency = source.currency,
            credit_limit = source.credit_limit,
            risk_level = source.risk_level,
            notes = source.notes,
            is_active = source.is_active,
            synced = 0,
            updated_at = GETDATE()

    WHEN NOT MATCHED THEN
        INSERT (sage_id, code, company_name, contact_name, email, phone, address,
                payment_delay, currency, credit_limit, max_days_overdue, risk_level,
                notes, is_active, synced)
        VALUES (source.sage_id, source.code, source.company_name, source.contact_name,
                source.email, source.phone, source.address, source.payment_delay,
                source.currency, source.credit_limit, source.max_days_overdue,
                source.risk_level, source.notes, source.is_active, 0);
END;

-- Trigger pour synchroniser automatiquement les échéances depuis F_DOCENTETE
CREATE TRIGGER tr_sync_invoices_from_docentete
ON F_DOCENTETE
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    -- Synchroniser les échéances (qui sont des "factures" dans Sage 100)
    MERGE anonymes_invoices AS target
    USING (
        SELECT
            i.DO_Piece as sage_id,              -- ID unique de l'échéance
            i.DO_Ref as invoice_number,         -- Numéro de facture commun (pour regrouper les échéances)
            i.DO_Tiers as customer_sage_id,
            i.DO_Piece as reference,            -- DO_Piece comme référence de l'échéance
            'echeance' as type,                 -- Toutes sont des échéances dans votre app
            i.DO_Date as invoice_date,          -- Date de l'échéance
            ISNULL(i.DO_Devise, 'XOF') as currency,
            ISNULL(i.DO_TotalTTC, 0) as total_amount,  -- Montant de cette échéance
            i.DO_Motif as notes,
            'Sage100' as created_by
        FROM inserted i
        WHERE i.DO_Domaine = 0  -- Ventes seulement
          AND i.DO_Type IN (6, 7, 8)  -- Types d'échéances (à ajuster selon vos données)
          AND i.DO_Tiers IS NOT NULL
          AND i.DO_Tiers <> ''
          AND i.DO_Ref IS NOT NULL  -- Doit avoir un numéro de facture
          AND i.DO_Ref <> ''
    ) AS source ON target.sage_id = source.sage_id

    WHEN MATCHED THEN
        UPDATE SET
            invoice_number = source.invoice_number,     -- DO_Ref = numéro facture
            customer_sage_id = source.customer_sage_id,
            reference = source.reference,               -- DO_Piece = référence échéance
            type = source.type,
            invoice_date = source.invoice_date,
            currency = source.currency,
            total_amount = source.total_amount,
            notes = source.notes,
            created_by = source.created_by,
            synced = 0,
            updated_at = GETDATE()

    WHEN NOT MATCHED THEN
        INSERT (sage_id, invoice_number, customer_sage_id, reference, type,
                invoice_date, currency, total_amount, notes, created_by, synced)
        VALUES (source.sage_id, source.invoice_number, source.customer_sage_id,
                source.reference, source.type, source.invoice_date, source.currency,
                source.total_amount, source.notes, source.created_by, 0);
END;

-- Trigger pour mettre à jour automatiquement updated_at sur anonymes_customers
CREATE TRIGGER tr_anonymes_customers_updated_at
ON anonymes_customers
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE anonymes_customers
    SET updated_at = GETDATE()
    WHERE sage_id IN (SELECT sage_id FROM inserted);
END;

-- Trigger pour mettre à jour automatiquement updated_at sur anonymes_invoices
CREATE TRIGGER tr_anonymes_invoices_updated_at
ON anonymes_invoices
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE anonymes_invoices
    SET updated_at = GETDATE()
    WHERE sage_id IN (SELECT sage_id FROM inserted);
END;