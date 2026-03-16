Requirement Specification: World Deaf Sports Championship Accommodation System

1. Project Overview

The Championship Accommodation Management System (CAMS) is a web-based platform designed to facilitate the booking and management of hotels for international teams participating in various deaf sports championships occurring on shared dates.

2. User Roles

System Admin: Oversight of all championships, hotel listings, and global settings.

Country Manager (National Deaf Sports Federation): Manages their specific country's delegation, including athlete registration and hotel room assignments.

3. Functional Requirements

3.1 Administrator Module

The Admin acts as the host committee controller.

Championship Management:

Add Championship: Create a new title (e.g., "World Deaf Athletics 2024").

Set Dates: Define start and end dates for each specific championship.

Edit/Remove: Update championship details or delete them if canceled.

Hotel & Pricing Management:

Manage Hotels: Add hotels with names, descriptions, and locations.

Tiered Pricing: Set different prices based on room types (Single, Double, Triple) and specific championships.

Inventory Control: Set total room allotments available for the event.

Global Overview: View a master list of all countries, their total booked athletes, and total costs.

3.2 Country Manager Module (International Delegations)

Each country is assigned a unique login to manage their team.

Athlete (Participant) Management:

Roster Upload: Add, edit, or remove athletes/officials.

Profile Details: Capture name, gender (critical for rooming), and sport category.

Accommodation Booking:

Hotel Selection: Browse available hotels and their specific price points.

Room Selection: Select room types (Single, Twin, etc.) based on the delegation's budget.

Rooming List (Assignment): * Drag-and-drop or select specific athletes to put into specific rooms.

Validation to ensure gender-appropriate room sharing (unless otherwise specified).

Financial Summary:

Generate a real-time invoice/summary of total accommodation costs based on selected hotels and room types.

4. Technical Workflows

4.1 Championship Setup Workflow

Admin logs in -> Navigates to "Championships".

Admin creates "World Deaf Tennis" and "World Deaf Swimming" on the same dates.

Admin assigns "Hotel A" to Tennis and "Hotel B" to Swimming, or both to both.

4.2 Country Booking Workflow

Country Manager (e.g., USA) logs in.

They input their list of 20 athletes.

They navigate to "Bookings" and select a Championship.

They select "Hotel A" and reserve 10 Double Rooms.

They assign Athlete 1 and Athlete 2 to Room 101.

5. Non-Functional Requirements

Accessibility: Must follow WCAG guidelines to be accessible to deaf and hard-of-hearing users (clear visual cues, no reliance on audio alerts).

Data Security: Passport numbers and personal athlete data must be encrypted.

Mobile Responsive: Country managers should be able to check their rooming list on-site via mobile devices.

Concurrency: The system must handle multiple country managers booking rooms simultaneously without "double-booking" the same room inventory.

6. Proposed Data Structure (High-Level)

Championships: id, title, start_date, end_date, location

Hotels: id, name, address, total_rooms

RoomTypes: id, hotel_id, name, capacity, price_per_night

Athletes: id, country_id, name, gender, championship_id

Bookings: id, room_type_id, country_id, athlete_ids[]