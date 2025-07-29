# Expenzai

A Laravel 12 REST API that powers an AI-driven expense tracking application. This API handles receipt uploads, processes them using OpenAI's GPT-4 Vision, and provides expense analytics.

## Features

- 🔐 **Sanctum Authentication** - Secure API token-based authentication
- 🤖 **AI Receipt Processing** - OpenAI GPT-4 Vision integration for automatic item extraction
- 📊 **Expense Analytics** - Weekly summaries and category breakdowns
- 🔄 **Queue Processing** - Background jobs for AI processing
- 📁 **File Management** - Receipt image storage and handling
- ✏️ **Item Management** - Manual category editing and uncertainty flagging

## Tech Stack

- **Laravel 12** - Latest PHP framework
- **MySQL** - Primary database
- **Laravel Sanctum** - API authentication
- **Laravel Queues** - Background job processing
- **OpenAI API** - GPT-4 Vision for receipt analysis
- **Intervention Image** - Image processing

## Installation

### Requirements
- PHP 8.2+
- Composer
- MySQL 8.0+
- OpenAI API key

### Setup

```bash
# Clone repository
git clone <your-repo-url>
cd receipt-tracker-api

# Install dependencies
composer install

# Environment setup
cp .env.example .env
php artisan key:generate
```

### Environment Configuration

Edit `.env`:

```bash
APP_NAME="Expenzai"
APP_ENV=local
APP_DEBUG=true
API_URL=http://receipt-tracker-api.test

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=receipt_tracker_api
DB_USERNAME=root
DB_PASSWORD=

# Queue Configuration
QUEUE_CONNECTION=database

# File Storage
FILESYSTEM_DISK=public

# OpenAI Integration
OPENAI_API_KEY=your_openai_api_key_here
```

### Database Setup

```bash
# Create database
mysql -u root -p -e "CREATE DATABASE receipt_tracker_api"

# Run migrations
php artisan migrate

# Set up queue tables
php artisan queue:table
php artisan migrate

# Create storage link
php artisan storage:link
```

## Running the Application

```bash
# Start development server
php artisan serve

# Start queue worker (separate terminal)
php artisan queue:work
```

The API will be available at `http://localhost:8000` or your configured URL.

## API Endpoints

### Authentication

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/register` | User registration |
| POST | `/api/login` | User login |
| POST | `/api/logout` | User logout |

### Receipts

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/receipts` | List user receipts |
| POST | `/api/receipts` | Upload receipt image |
| GET | `/api/receipts/{id}` | Get receipt details |
| DELETE | `/api/receipts/{id}` | Delete receipt |

### Items & Categories

| Method | Endpoint | Description |
|--------|----------|-------------|
| PUT | `/api/items/{id}` | Update item category |

### Analytics

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/expenses/weekly?date=YYYY-MM-DD` | Weekly expense summary |
| GET | `/api/expenses/summary` | Monthly overview |

## API Usage Examples

### Register User
```bash
curl -X POST http://localhost:8000/api/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Doe",
    "email": "john@example.com", 
    "password": "password123",
    "password_confirmation": "password123"
  }'
```

### Upload Receipt
```bash
curl -X POST http://localhost:8000/api/receipts \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "image=@receipt.jpg"
```

### Get Weekly Summary
```bash
curl -X GET "http://localhost:8000/api/expenses/weekly?date=2025-01-15" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## Models & Database Schema

### Users
- Standard Laravel user model with Sanctum tokens

### Receipts
```php
- id (primary key)
- user_id (foreign key)
- image_path (string)
- total_amount (decimal)
- store_name (string, nullable)
- receipt_date (date, nullable)  
- status (enum: processing, completed, failed)
- week_of (date, nullable)
- timestamps
```

### Receipt Items
```php
- id (primary key)
- receipt_id (foreign key)
- name (string)
- price (decimal)
- category (string)
- is_uncertain (boolean)
- timestamps
```

### Categories
```php
- id (primary key)
- name (string, unique)
- color (string)
- timestamps
```

## AI Processing

The API uses OpenAI's GPT-4 Vision to process receipt images:

1. **Image Upload** - Receipt stored in `storage/app/public/receipts`
2. **Queue Job** - `ProcessReceiptJob` dispatched for background processing
3. **AI Analysis** - Image sent to OpenAI Vision API with structured prompt
4. **Data Extraction** - Items, prices, categories, and metadata extracted
5. **Storage** - Results stored in database with uncertainty flags

### Categories
- Food & Groceries
- Household
- Personal Care
- Beverages
- Snacks
- Meat & Deli
- Dairy
- Vegetables
- Fruits
- Other

## Queue Management

Monitor and manage background jobs:

```bash
# Start queue worker
php artisan queue:work

# Monitor queue status
php artisan queue:monitor

# View failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all
```

## File Structure

```
app/
├── Http/Controllers/Api/
│   ├── AuthController.php
│   ├── ReceiptController.php
│   ├── ItemController.php
│   └── ExpenseController.php
├── Jobs/
│   └── ProcessReceiptJob.php
├── Models/
│   ├── User.php
│   ├── Receipt.php
│   ├── ReceiptItem.php
│   └── Category.php
└── Policies/
    ├── ReceiptPolicy.php
    └── ReceiptItemPolicy.php

routes/
└── api.php

config/
└── services.php (OpenAI configuration)
```

## Testing

```bash
# Run feature tests
php artisan test

# Run specific test
php artisan test --filter=ReceiptTest
```

## Deployment

### Environment Variables (Production)
```bash
APP_ENV=production
APP_DEBUG=false
QUEUE_CONNECTION=database
DB_CONNECTION=mysql
OPENAI_API_KEY=your_production_key
```

## Security

- All routes protected with Sanctum authentication
- File upload validation (image types, size limits)
- Authorization policies for resource access
- CSRF protection disabled for API routes
- Input validation on all endpoints

## Error Handling

The API returns consistent JSON error responses:

```json
{
  "message": "Error description",
  "errors": {
    "field": ["Validation error"]
  }
}
```

## Performance

- Database indexes on frequently queried columns
- Queue processing for expensive AI operations
- Image optimization and storage management
- API rate limiting (configurable)

## Contributing

1. Fork the repository
2. Create a feature branch
3. Follow PSR-12 coding standards
4. Add tests for new features
5. Submit a pull request

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).