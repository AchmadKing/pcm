-- Add actual_price column to project_items table
-- The current 'price' column will be renamed to represent UP (Unit Price)
-- actual_price is the new column for actual/real prices

ALTER TABLE project_items 
ADD COLUMN actual_price DECIMAL(15,2) DEFAULT NULL AFTER price;

-- Update comment for clarity
-- price = Harga UP (Unit Price from budget)
-- actual_price = Harga Aktual (Actual market price, optional)
