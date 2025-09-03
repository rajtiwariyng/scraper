# Product Data Scraper Tool

A comprehensive Laravel PHP application for scraping product data from multiple e-commerce platforms including Amazon India, Flipkart, VijaySales, Reliance Digital, and Croma. The application features automated scheduling, database management, and a monitoring dashboard.

## Features

### 🚀 Core Features

-   **Multi-Platform Scraping**: Supports 5 major e-commerce platforms
-   **Automated Scheduling**: Runs automatically every 2 days using Laravel scheduler
-   **Data Management**: Intelligent data updates and deduplication
-   **Monitoring Dashboard**: Real-time monitoring with charts and statistics
-   **Error Handling**: Comprehensive error tracking and retry mechanisms
-   **Data Validation**: Robust data sanitization and validation

### 📊 Data Collected

For each product, the scraper collects:

-   Platform name and SKU/Product ID
-   Product name and description
-   Pricing information (regular and sale prices)
-   Offers and discounts
-   Inventory/stock status
-   Ratings and review counts
-   Product variants and specifications
-   Brand, model, and technical details
-   Images and videos
-   Last scraped timestamp

### 🎯 Supported Platforms

1. **Amazon India** - Comprehensive Product catalog
2. **Flipkart** - Wide range of products
3. **VijaySales** - Electronics retailer
4. **Reliance Digital** - Digital products marketplace
5. **Croma** - Electronics and appliances

## Requirements

### System Requirements

-   **PHP**: 8.1 or higher
-   **Database**: MySQL 5.7+ or MariaDB 10.3+
-   **Web Server**: Apache or Nginx
-   **Memory**: Minimum 512MB RAM (2GB recommended)
-   **Storage**: At least 1GB free space

### PHP Extensions

-   PDO MySQL
-   cURL
-   OpenSSL
-   Mbstring
-   XML
-   JSON
-   GD (optional, for image processing)

## Installation

### 1. Download and Extract

```bash
# Extract the project files
unzip Product-scraper.zip
cd Product-scraper
```

### 2. Install Dependencies

```bash
# Install Composer dependencies
composer install --no-dev --optimize-autoloader
```

### 3. Environment Configuration

```bash
# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate
```

### 4. Database Setup

Edit `.env` file with your database credentials:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=Product_scraper
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

Create the database:

```sql
CREATE DATABASE Product_scraper CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Run migrations:

```bash
php artisan migrate
```

### 5. Configure Scraper Settings

Edit scraper configuration in `.env`:

```env
# Scraper Configuration
SCRAPER_DELAY_MIN=2
SCRAPER_DELAY_MAX=5
SCRAPER_TIMEOUT=30
SCRAPER_RETRIES=3
SCRAPER_USER_AGENT="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"
```

### 6. Set Up Cron Job

Run the automated setup script:

```bash
chmod +x setup-cron.sh
./setup-cron.sh
```

Or manually add to crontab:

```bash
crontab -e
# Add this line:
* * * * * cd /path/to/product-scraper && php artisan schedule:run >> /dev/null 2>&1
```

### 7. Web Server Configuration

#### Apache

Create a virtual host pointing to the `public` directory:

```apache
<VirtualHost *:80>
    DocumentRoot /path/to/product-scraper/public
    ServerName product-scraper.local

    <Directory /path/to/product-scraper/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

#### Nginx

```nginx
server {
    listen 80;
    server_name product-scraper.local;
    root /path/to/product-scraper/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## Usage

### Command Line Interface

#### Run Scraper Manually

```bash
# Scrape all platforms
php artisan scraper:run all

# Scrape specific platform
php artisan scraper:run amazon

# Force scraping (ignore recent scrape check)
php artisan scraper:run all --force

# Limit products per platform
php artisan scraper:run all --limit=100
```

#### Check System Status

```bash
# Overall system status
php artisan scraper:status

# Platform-specific status
php artisan scraper:status --platform=amazon

# Detailed statistics
php artisan scraper:status --detailed --days=30
```

#### Cleanup Old Data

```bash
# Clean up with default settings
php artisan scraper:cleanup

# Custom retention periods
php artisan scraper:cleanup --logs=14 --inactive=60

# Dry run (see what would be deleted)
php artisan scraper:cleanup --dry-run
```

### Web Dashboard

Access the monitoring dashboard at: `http://your-domain/dashboard`

#### Dashboard Features

-   **Overview**: System health, statistics, and performance metrics
-   **Platform Details**: Individual platform performance and data
-   **Products**: Browse and search scraped products
-   **Logs**: View scraping session logs and errors
-   **Real-time Updates**: Auto-refreshing data and charts

## Configuration

### Platform URLs

Edit `config/scraper.php` to modify platform URLs:

```php
'platforms' => [
    'amazon' => [
        'name' => 'Amazon India',
        'base_url' => 'https://www.amazon.in',
        'category_urls' => [
            'https://www.amazon.in/s?k=laptops&rh=n%3A1375424031',
            // Add more URLs as needed
        ]
    ],
    // ... other platforms
]
```

### Scraper Behavior

Modify scraper settings in `config/scraper.php`:

```php
'timeout' => 30,                    // Request timeout in seconds
'retries' => 3,                     // Number of retry attempts
'delay_min' => 2,                   // Minimum delay between requests
'delay_max' => 5,                   // Maximum delay between requests
'max_execution_time' => 7200,       // Maximum scraping time (2 hours)
```

### Scheduling

Modify the schedule in `app/Console/Kernel.php`:

```php
// Run every 48 hours
$schedule->command('scraper:run all')
         ->twiceDaily(2, 14)
         ->withoutOverlapping(7200);
```

## Monitoring and Maintenance

### Log Files

-   **Application Logs**: `storage/logs/laravel.log`
-   **Scraper Logs**: `storage/logs/scraper.log`
-   **Schedule Logs**: `storage/logs/scraper-schedule.log`
-   **Cleanup Logs**: `storage/logs/cleanup.log`

### Database Maintenance

```bash
# Check database size and statistics
php artisan scraper:status --detailed

# Clean up old data
php artisan scraper:cleanup

# Optimize database tables
php artisan db:optimize
```

### Performance Monitoring

-   Monitor CPU and memory usage during scraping
-   Check disk space regularly
-   Review error logs for issues
-   Monitor database performance

## Troubleshooting

### Common Issues

#### 1. Scraper Not Running

```bash
# Check if cron is working
php artisan schedule:list

# Test scheduler manually
php artisan schedule:run

# Check logs
tail -f storage/logs/scraper-schedule.log
```

#### 2. Database Connection Issues

```bash
# Test database connection
php artisan migrate:status

# Check database credentials in .env
# Ensure database exists and user has permissions
```

#### 3. Memory Issues

```bash
# Increase PHP memory limit in php.ini
memory_limit = 512M

# Or set in .env
PHP_MEMORY_LIMIT=512M
```

#### 4. Timeout Issues

```bash
# Increase timeouts in config/scraper.php
'timeout' => 60,
'max_execution_time' => 14400,  // 4 hours
```

### Error Codes

-   **HTTP 403**: Blocked by website (adjust user agent or delays)
-   **HTTP 429**: Rate limited (increase delays between requests)
-   **HTTP 503**: Service unavailable (retry later)
-   **Connection timeout**: Increase timeout settings

## API Reference

### REST Endpoints

```
GET /dashboard/api/stats?days=7
```

Returns system statistics and performance data.

### Database Schema

#### Products Table

-   `id`: Primary key
-   `platform`: E-commerce platform name
-   `sku`: Product SKU/ID
-   `title`: Product title
-   `description`: Product description
-   `price`: Regular price
-   `sale_price`: Discounted price
-   `rating`: Average rating (0-5)
-   `review_count`: Number of reviews
-   `brand`: brand
-   `specifications`: JSON with technical specs
-   `image_urls`: JSON array of image URLs
-   `is_active`: Product status
-   `scraped_date`: Last update timestamp

#### Scraping Logs Table

-   `id`: Primary key
-   `platform`: Platform name
-   `status`: Session status (started, completed, failed)
-   `products_found`: Number of products discovered
-   `products_added`: New products added
-   `products_updated`: Existing products updated
-   `errors_count`: Number of errors encountered
-   `duration_seconds`: Session duration
-   `created_at`: Session start time

## Security Considerations

### Data Protection

-   Scraper respects robots.txt when possible
-   Implements delays to avoid overwhelming servers
-   Uses appropriate user agents
-   Handles rate limiting gracefully

### Application Security

-   Input validation and sanitization
-   SQL injection protection via Eloquent ORM
-   CSRF protection on web forms
-   Secure configuration defaults

## Performance Optimization

### Database Optimization

```sql
-- Add indexes for better performance
CREATE INDEX idx_platform_active ON products(platform, is_active);
CREATE INDEX idx_brand_active ON products(brand, is_active);
CREATE INDEX idx_last_scraped ON products(scraped_date);
```

### Caching

```bash
# Enable configuration caching
php artisan config:cache

# Enable route caching
php artisan route:cache

# Enable view caching
php artisan view:cache
```

## Contributing

### Development Setup

```bash
# Install development dependencies
composer install

# Run tests
php artisan test

# Code style checking
./vendor/bin/phpcs

# Code formatting
./vendor/bin/phpcbf
```

### Adding New Platforms

1. Create new scraper class extending `BaseScraper`
2. Implement required abstract methods
3. Add platform configuration to `config/scraper.php`
4. Update dashboard navigation
5. Test thoroughly

## License

This project is licensed under the MIT License. See LICENSE file for details.

## Support

For support and questions:

-   Check the troubleshooting section
-   Review log files for errors
-   Ensure all requirements are met
-   Verify configuration settings

## Changelog

### Version 1.0.0

-   Initial release
-   Support for 5 e-commerce platforms
-   Automated scheduling
-   Web dashboard
-   Comprehensive logging
-   Data validation and sanitization
