# Database Map

Database: `sciencespark_lab_db`

No destructive schema changes were made.

## Tables

### users

Columns: `id`, `username`, `email`, `hashed_password`, `role`

Constraints: primary key `id`; unique indexes on `username` and `email`.

Observed roles: `admin`, `manager`, `technician`.

### customers

Columns: `id`, `name`, `contact_person`, `email`, `phone`, `address`

Constraints: primary key `id`.

### categories

Columns: `id`, `name`

Constraints: primary key `id`; unique `name`.

### instruments

Columns: `id`, `name`, `model`, `serial_number`, `manufacturer`, `location`, `status`, `purchase_date`, `customer_id`, `search_vector`, `qr_code_path`

Constraints: primary key `id`; unique `serial_number`; foreign key `customer_id -> customers.id`.

Indexes: GIN `search_vector`.

### maintenance_records

Columns: `id`, `instrument_id`, `date`, `type`, `description`, `technician`, `next_due_date`

Constraints: primary key `id`; foreign key `instrument_id -> instruments.id`.

### service_reports

Columns: `id`, `instrument_id`, `date`, `report_file_path`, `summary`, `technician`

Constraints: primary key `id`; foreign key `instrument_id -> instruments.id`.

### service_requests

Columns: `id`, `instrument_id`, `customer_id`, `description`, `status`, `assigned_technician`, `created_date`, `resolved_date`

Constraints: primary key `id`; foreign keys to `instruments.id` and `customers.id`.

### documents

Columns: `id`, `title`, `category`, `file_path`, `instrument_id`, `uploaded_by`, `upload_date`, `description`, `search_vector`

Constraints: primary key `id`; foreign key `instrument_id -> instruments.id`.

Indexes: GIN `search_vector`.

### notifications

Columns: `id`, `title`, `message`, `category`, `severity`, `is_read`, `maintenance_record_id`, `created_at`

Constraints: primary key `id`; foreign key `maintenance_record_id -> maintenance_records.id`.

Defaults: `category = maintenance`, `severity = info`, `is_read = false`, `created_at = CURRENT_TIMESTAMP`.

### activity_logs

Columns: `id`, `user_id`, `username`, `action`, `entity_type`, `entity_id`, `details`, `timestamp`

Constraints: primary key `id`; foreign key `user_id -> users.id`.

### alembic_version

Columns: `version_num`

Tracks previous Alembic migration version.
