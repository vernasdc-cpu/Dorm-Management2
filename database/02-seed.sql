USE heart_dorm_db;

INSERT IGNORE INTO roles (slug,name) VALUES
('super_admin','Super Admin'),('admin_staff','Admin Staff'),('housekeeping','Housekeeping Staff'),('resident','Dormitory Resident');

INSERT IGNORE INTO floors (name) VALUES ('3rd Floor'),('4th Floor'),('5th Floor'),('6th Floor'),('Ground Floor'),('2nd Floor');
INSERT IGNORE INTO room_types (name,description) VALUES ('Solo','Single occupant room'),('Shared','Shared room up to 4 occupants');

INSERT IGNORE INTO inventory_categories (name,description) VALUES
('Dormitory Equipment','Beds, cabinets, curtains'),
('Linen','Bedsheets, pillowcases, blankets, towels'),
('Cleaning Supplies','Brooms, rags, soap powder, bleach and more');

INSERT IGNORE INTO system_settings (setting_key,setting_value) VALUES
('dorm_name','Heart Ladies Dormitory'),
('dorm_address','Manila, Philippines'),
('dorm_phone','(02) 8555-0100'),
('low_stock_default','10'),
('contract_warning_days','30,15,7');

-- Rooms: floor ids 1..4 (3rd..6th), type ids 1=Solo 2=Shared
INSERT IGNORE INTO rooms (room_number,floor_id,room_type_id,aircon,max_occupants,monthly_rate,status,description) VALUES
('301',1,1,'Aircon',1,6500,'Available','Solo aircon room, 3rd floor'),
('302',1,2,'Non-Aircon',4,3500,'Partially Occupied','Shared non-aircon, 3rd floor'),
('303',1,2,'Aircon',4,4500,'Available','Shared aircon, 3rd floor'),
('401',2,1,'Non-Aircon',1,5000,'Available','Solo non-aircon, 4th floor'),
('402',2,2,'Aircon',4,4500,'Partially Occupied','Shared aircon, 4th floor'),
('403',2,2,'Non-Aircon',4,3500,'Available','Shared non-aircon, 4th floor'),
('501',3,1,'Aircon',1,6500,'Reserved','Solo aircon, 5th floor'),
('502',3,2,'Aircon',4,4500,'Available','Shared aircon, 5th floor'),
('503',3,2,'Non-Aircon',4,3500,'Under Maintenance','Plumbing repair ongoing'),
('601',4,1,'Aircon',1,7000,'Available','Solo aircon premium, 6th floor'),
('602',4,2,'Aircon',4,4800,'Available','Shared aircon, 6th floor'),
('603',4,2,'Non-Aircon',4,3500,'Available','Shared non-aircon, 6th floor');

-- Beds for each room (B1..Bn)
-- Generated manually for the 12 rooms above (ids assumed 1..12 in fresh DB)
INSERT IGNORE INTO beds (room_id,bed_number,status) VALUES
(1,'B1','vacant'),
(2,'B1','vacant'),(2,'B2','vacant'),(2,'B3','vacant'),(2,'B4','vacant'),
(3,'B1','vacant'),(3,'B2','vacant'),(3,'B3','vacant'),(3,'B4','vacant'),
(4,'B1','vacant'),
(5,'B1','vacant'),(5,'B2','vacant'),(5,'B3','vacant'),(5,'B4','vacant'),
(6,'B1','vacant'),(6,'B2','vacant'),(6,'B3','vacant'),(6,'B4','vacant'),
(7,'B1','vacant'),
(8,'B1','vacant'),(8,'B2','vacant'),(8,'B3','vacant'),(8,'B4','vacant'),
(9,'B1','vacant'),(9,'B2','vacant'),(9,'B3','vacant'),(9,'B4','vacant'),
(10,'B1','vacant'),
(11,'B1','vacant'),(11,'B2','vacant'),(11,'B3','vacant'),(11,'B4','vacant'),
(12,'B1','vacant'),(12,'B2','vacant'),(12,'B3','vacant'),(12,'B4','vacant');

-- Inventory (category ids: 1 equipment, 2 linen, 3 cleaning)
INSERT IGNORE INTO inventory_items (name,category_id,quantity,unit,available_qty,assigned_qty,damaged_qty,min_stock,location,condition_status,date_purchased,supplier,cost,remarks) VALUES
('Bed',1,40,'pcs',36,4,0,5,'Storage A','Good','2024-01-15','Furniture Co.',2500.00,'Single beds'),
('Cabinet',1,40,'pcs',37,3,0,5,'Storage A','Good','2024-01-15','Furniture Co.',1800.00,'Wardrobe cabinets'),
('Curtain',1,60,'pcs',55,5,0,10,'Storage A','Good','2024-06-01','Textile Supplier',250.00,'Window curtains'),
('Bedsheet',2,120,'pcs',110,10,0,20,'Linen Room','Good','2024-06-01','Textile Supplier',300.00,'Single bed sheets'),
('Pillowcase',2,120,'pcs',112,8,0,20,'Linen Room','Good','2024-06-01','Textile Supplier',120.00,'Standard pillowcases'),
('Blanket',2,80,'pcs',70,10,0,15,'Linen Room','Good','2024-03-10','Textile Supplier',450.00,'Fleece blankets'),
('Towel',2,100,'pcs',90,10,0,20,'Linen Room','Good','2024-03-10','Textile Supplier',200.00,'Bath towels'),
('Broom',3,25,'pcs',20,5,0,8,'Utility Room','Good','2024-05-01','CleanMart',150.00,'Stick brooms'),
('Rags',3,100,'pcs',85,15,0,20,'Utility Room','Good','2024-05-01','CleanMart',25.00,'Cleaning rags'),
('Soap Powder',3,40,'kg',30,10,0,10,'Utility Room','Good','2024-07-01','CleanMart',90.00,'Laundry soap'),
('Bleach',3,15,'bottles',5,10,0,10,'Utility Room','Good','2024-07-01','CleanMart',120.00,'Low stock demo item'),
('Mop',3,20,'pcs',16,4,0,6,'Utility Room','Good','2024-05-01','CleanMart',220.00,'Spin mops');
