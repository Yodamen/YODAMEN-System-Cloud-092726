# Phase 2C Parity Correction 06

- Replaced full browser reload behavior on the title refresh controls with a soft in-page data refresh.
- Bookings refreshes its booking list and bootstrap data without unmounting the application.
- Queuing refreshes its current queue and bootstrap data without blanking the screen.
- Servicing refreshes active visits and bootstrap data without blanking the screen.
- The circular sync icon briefly spins while the refresh request is running.
- No migration or seeder is required.
