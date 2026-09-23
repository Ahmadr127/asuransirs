php artisan queue:work --timeout=1800 --tries=1 --sleep=3 --memory=512

php artisan queue:work --timeout=5400 --memory=1024

createdb -h 127.0.0.1 -U postgres starter_restore
psql -h 127.0.0.1 -U postgres -d starter_restore -f sql\backup_starter_2026-09-23_233143.sql