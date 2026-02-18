Hey Claude,

this is a clean slate repo, where i need you to create a new mini application from scratch.

The Idea is pretty simple:

I want this website to display bookable workshops in tiles.

Basically. if you go onto it (the url is going to be “workshops.disinfoconsulting.eu”) you should see a list of bookable workshops.

Every workshop should have title, description, date, how many seats are still open and a price.

When you click on one of the workshops, you should get to the detail page of the workshop, where you can see the same stuff again plus location, detailed description, target group, language and so on

you should also be able to reserve a spot for the workshop by booking it basically.

so, very simply, i want to have a plattform where i can get people to sign onto our workshops.

Now, to manage this site, i want an admin panel, where i can create, delete, and configure the workshops. I also want the possibility to draft some, to set them to “live” and to put them back into draft mode. Only live workshops should be visible.

–

Now, the booking logic needs to be smart. In the admin panel, i should see all the people that have booked themselves a seat at the workshop. 

When booking a workshop, the person should have to input that classic data that everybody needs to input (name, email, phone).

After booking, first up the people that register need to get an email (you are booked etc here is when where and we are looking forward, if you storno one week before, is okay, everything alter than that is 50% of the costs.)

Maybe also get an automated invoice in there. The data for that invoice should also be adaptable in the admin panel.

After that, in the admin panel the teilnehmerliste should be exportable, editable, and manageable. Like uninvite somebody (for example if they are sick or something) and so on.

–
Teck stack vise, i want this to be build on a php infrastructure. How you handel the data is up to you! I want you to know that this is going to run on cpanel on my server. However you are going to handle the data, the thing that is really important to me is that im not going to do anything - so however you choose to store the data, it should be already backed into the system.

Lastly, two very simple principles:

This programm needs to be safe from outside interference. Make it standard safe, doesnt have to be fort nox, but make it so that ddosing it or adding security headers is the norm please.

second, the design needs to be close to how this site is designed:

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Desinformation Consulting - Solutions</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #050505;
        }

        #dc-solutions-dark {
            /* PALETTE */
            --sol-bg: #050505;
            --sol-card-bg: rgba(255, 255, 255, 0.03);
            --sol-card-hover: rgba(255, 255, 255, 0.07);
            --sol-border: rgba(255, 255, 255, 0.1);
            --sol-border-hover: rgba(255, 255, 255, 0.4);
            --sol-text: #ffffff;
            --sol-muted: #a0a0a0;
            --font-body: 'Inter', sans-serif;
            --font-heading: 'Cardo', serif;
            
            /* SHARED RADIUS */
            --radius-shared: 6px; 
        }

        #dc-solutions-dark {
            position: relative;
            width: 100%;
            background-color: var(--sol-bg);
            color: var(--sol-text);
            /* Generous padding that scales */
            padding: clamp(5rem, 10vh, 8rem) 1.5rem;
            font-family: var(--font-body);
            isolation: isolate;
            box-sizing: border-box;
            overflow: hidden; /* Prevents horizontal scrollbar caused by animations */
        }

        /* HEADER */
        .solutions-header {
            text-align: center;
            max-width: 800px;
            margin: 0 auto 5rem auto; /* Increased bottom margin */
            padding: 0 1rem;
        }

        .solutions-eyebrow {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 3px;
            color: var(--sol-muted);
            margin-bottom: 1rem;
            display: block;
            font-weight: 600;
        }

        .solutions-title {
            font-family: var(--font-heading);
            font-size: clamp(2rem, 5vw, 3.2rem);
            font-weight: 400;
            margin: 0;
            line-height: 1.2;
            color: #fff;
            
            /* Prevent headline overflow */
            overflow-wrap: break-word;
            word-wrap: break-word;
            hyphens: auto;
        }

        /* GRID LAYOUT */
        .solutions-grid {
            display: grid;
            /* Desktop: 3 cols. Tablet/Mobile: 1 col */
            grid-template-columns: repeat(3, 1fr);
            gap: 2rem;
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
            box-sizing: border-box;
        }

        /* CARD STYLING */
        .sol-card {
            position: relative;
            background: var(--sol-card-bg);
            border: 1px solid var(--sol-border);
            padding: 2.5rem 2rem;
            
            /* UPDATED: Matches Button Radius */
            border-radius: var(--radius-shared); 
            
            transition: all 0.4s cubic-bezier(0.2, 0.8, 0.2, 1);
            display: flex;
            flex-direction: column;
            height: 100%;
            box-sizing: border-box;
        }

        .sol-card:hover {
            background: var(--sol-card-hover);
            border-color: var(--sol-border-hover);
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
        }

        .sol-icon {
            width: 40px;
            height: 40px;
            margin-bottom: 1.5rem;
            color: #fff;
            opacity: 0.8;
            transition: transform 0.4s ease;
            flex-shrink: 0; /* Prevents icon from squishing */
        }
        
        .sol-card:hover .sol-icon {
            transform: scale(1.1);
            opacity: 1;
        }

        .sol-card h3 {
            font-family: var(--font-heading);
            font-size: 1.75rem;
            margin: 0 0 1rem 0;
            font-weight: 400;
            color: #fff;
            line-height: 1.3;
        }

        .sol-main-text {
            font-size: 1.05rem;
            color: #fff;
            font-weight: 500;
            margin-bottom: 1.5rem;
            line-height: 1.5;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding-bottom: 1rem;
        }

        /* GERMAN TYPOGRAPHY SAFETY */
        .sol-list-intro, .sol-list li, .sol-main-text, .sol-card h3 {
            /* Crucial for long German words on mobile */
            overflow-wrap: break-word;
            word-wrap: break-word;
            -webkit-hyphens: auto;
            -moz-hyphens: auto;
            -ms-hyphens: auto;
            hyphens: auto; 
        }

        .sol-list-intro {
            font-size: 0.9rem;
            color: var(--sol-muted);
            margin-bottom: 1rem;
        }

        .sol-list {
            list-style: none;
            padding: 0;
            margin: 0;
            flex-grow: 1; 
        }

        .sol-list li {
            position: relative;
            padding-left: 1.5rem;
            margin-bottom: 0.8rem;
            font-size: 0.95rem;
            color: #d0d0d0;
            line-height: 1.5;
        }

        .sol-list li::before {
            content: '';
            position: absolute;
            left: 0;
            top: 9px;
            width: 5px;
            height: 5px;
            background: #fff;
            opacity: 0.5;
        }

        /* CTA BUTTONS CONTAINER */
        .solutions-cta {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1.5rem; /* Matched gap from previous designs */
            
            /* MAJOR SPACING UPDATE */
            margin-top: 8rem; /* Huge distance from cards for pro look */
            
            width: 100%;
        }

        /* BUTTON SHARED STYLES (MATCHING HERO) */
        .btn-sol-base {
            display: inline-flex; /* Better centering */
            align-items: center;
            justify-content: center;
            
            /* UPDATED: Matches Hero Dimensions */
            padding: 16px 36px;
            font-size: 1rem;
            font-weight: 500;
            letter-spacing: 0.3px;
            border-radius: var(--radius-shared);
            
            text-decoration: none;
            transition: all 0.4s cubic-bezier(0.2, 0.8, 0.2, 1);
            min-width: 280px;
            text-align: center;
            box-sizing: border-box;
            white-space: nowrap;
        }

        /* Primary Button (Glassmorphism -> Solid White) */
        .btn-sol-primary {
            /* Default State: Glassy */
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(12px);
            color: white;
            box-shadow: 0 0 25px rgba(255, 255, 255, 0.05);
        }

        .btn-sol-primary:hover {
            /* Hover State: Solid White */
            background: #ffffff;
            border-color: #ffffff;
            color: #000000;
            box-shadow: 0 0 40px rgba(255, 255, 255, 0.3);
            transform: translateY(-3px);
        }

        /* Secondary Button (Outline -> Glassy) */
        .btn-sol-secondary {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.15); /* Glass border var */
            color: #cccccc;
        }

        .btn-sol-secondary:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: #ffffff;
            color: #ffffff;
            transform: translateY(-3px);
        }

        /* --- RESPONSIVE BREAKPOINTS --- */

        /* Tablet (Landscape & Portrait) - Switch to Stack */
        @media (max-width: 1024px) {
            .solutions-grid {
                grid-template-columns: 1fr; /* Stack them */
                max-width: 600px; /* Don't let them get too wide */
            }
            
            .solutions-header {
                margin-bottom: 4rem;
            }
        }

        /* Mobile */
        @media (max-width: 600px) {
            #dc-solutions-dark {
                padding-top: 4rem;
                padding-bottom: 4rem;
                padding-left: 1rem;
                padding-right: 1rem;
            }
            
            .solutions-grid {
                gap: 1.5rem;
            }
            
            .sol-card {
                padding: 2rem 1.5rem;
            }
            
            /* Buttons on mobile */
            .solutions-cta {
                margin-top: 5rem; /* Slightly less on mobile, still generous */
                gap: 1rem;
            }
            
            .btn-sol-base {
                width: 100%; /* Full width */
                min-width: unset; /* Remove constraint */
                padding: 16px;
            }
        }
    </style>
</head>
<body>

<section id="dc-solutions-dark">
    <div class="solutions-noise"></div>

    <div class="solutions-header">
        <span class="solutions-eyebrow">Zielgruppen &amp; Lösungen</span>
        <h2 class="solutions-title">Strategische Abwehr für Ihre Aufgaben, Risiken und Ziele.</h2>
    </div>

    <div class="solutions-grid">
        
        <div class="sol-card">
            <svg class="sol-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <circle cx="12" cy="12" r="10" />
                <path d="M12 6v12M6 12h12" />
            </svg>
            <h3>NGOs &amp;<br>Zivilgesellschaft</h3>
            <p class="sol-main-text">Schützen Sie das Vertrauen in Ihre Organisation.</p>
            <p class="sol-list-intro">Unsere Formate unterstützen Sie dabei:</p>
            <ul class="sol-list">
                <li>Glaubwürdigkeit in polarisierten Debatten sichern</li>
                <li>Bildungsarbeit zu Desinformation praxisnah gestalten</li>
                <li>Kommunikationsrisiken frühzeitig erkennen</li>
            </ul>
        </div>

        <div class="sol-card">
            <svg class="sol-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M3 21h18M5 21V7l8-4 8 4v14M13 10v4M9 10v4" />
            </svg>
            <h3>Unternehmen &amp;<br>Wirtschaft</h3>
            <p class="sol-main-text">Erfüllen Sie Ihre Pflichten – bevor es kritisch wird.</p>
            <p class="sol-list-intro">Unsere Workshops helfen Ihnen:</p>
            <ul class="sol-list">
                <li>Gesetzliche Anforderungen (ESG/NIS2) erfüllen</li>
                <li>Interne Awareness für Manipulation schaffen</li>
                <li>Reputations- und Sicherheitsrisiken abwehren</li>
            </ul>
        </div>

        <div class="sol-card">
            <svg class="sol-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M4 10v11M20 10v11M2 21h20M2 6h20l-10-4-10 4zM12 10v11" />
            </svg>
            <h3>Öffentliche Verwaltung &amp; Behörden</h3>
            <p class="sol-main-text">Stärken Sie die Widerstandsfähigkeit Ihrer Institution.</p>
            <p class="sol-list-intro">Unsere Beratung bereitet vor:</p>
            <ul class="sol-list">
                <li>Desinformationskampagnen frühzeitig erkennen</li>
                <li>Koordiniert auf hybride Bedrohungen reagieren</li>
                <li>Vertrauen in demokratische Institutionen schützen</li>
            </ul>
        </div>
    </div>

    <div class="solutions-cta">
        <a href="https://disinfoconsulting.eu/whitepaper-anfordern/" class="btn-sol-base btn-sol-primary">
            Whitepaper anfordern
        </a>
        <a href="https://disinfoconsulting.eu/kontakt/" class="btn-sol-base btn-sol-secondary">
            Kontakt aufnehmen
        </a>
    </div>

</section>

</body>
</html>
