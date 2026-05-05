# Docker Usage

This application can run in Docker using the provided Dockerfile and docker-compose.yml.

## Development setup

For development with live code reloading:

1. Start containers:
   `ash
   docker compose up --build
   `
2. Open http://localhost:8080 in your browser.

This uses bind mounts to sync your local src/ directory with the container.

## Production setup

For production deployment:

1. Build and start containers:
   `ash
   docker compose -f docker-compose.prod.yml up --build -d
   `
2. Open http://localhost:8080 in your browser.

This builds the application into the image and uses a persistent database volume.

## Environment variables

The app uses environment variables for database connection (defined in .env):

- DB_HOST
- DB_NAME
- DB_USER
- DB_PASSWORD
- DB_PORT

## Database setup

After starting the containers, you need to initialize the database:

1. Connect to the MySQL container:
   `ash
   docker compose exec db mysql -u root -p
   `
2. Run the SQL setup:
   `sql
   USE giftreg;
   SOURCE /docker-entrypoint-initdb.d/create-phpgiftregdb.sql;
   `

Or copy the SQL file and run it manually.
