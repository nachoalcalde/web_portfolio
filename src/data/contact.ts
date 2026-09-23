/**
 * The enquiry flow's questions, shared by the page that renders them and the
 * script that drives them: the markup is server-rendered from this list, and
 * the client re-reads it on every step, so the two cannot drift.
 */

export const CONTACT_EMAIL = 'ialcaldecid@gmail.com';

/**
 * Where the answers are posted. It is a file in `public/`, so the build copies
 * it next to the pages and Hostinger runs it as PHP from the same origin: no
 * CORS, no third party, no key in the browser. Swapping the sending for a
 * transactional API later is a change inside that one file.
 */
export const CONTACT_ENDPOINT = '/api/contact.php';

export interface ContactStep {
  /** Key under which the answer is stored, and the mailto line it feeds. */
  id: string;
  question: string;
  /** Short label for the review list. */
  review: string;
  /** Also the empty-state text of the review row. */
  placeholder?: string;
  kind: 'input' | 'textarea' | 'options';
  type?: 'text' | 'email';
  /** Passed straight to the field, so the browser can fill what it knows. */
  autocomplete?: string;
  required: boolean;
  options?: string[];
}

export const STEPS: ContactStep[] = [
  {
    id: 'name',
    question: "First things first — what's your name?",
    review: 'Your name',
    placeholder: 'Type your name',
    kind: 'input',
    type: 'text',
    autocomplete: 'name',
    required: true,
  },
  {
    id: 'email',
    question: 'Where should I write back?',
    review: 'Your email',
    placeholder: 'you@company.com',
    kind: 'input',
    type: 'email',
    autocomplete: 'email',
    required: true,
  },
  {
    id: 'org',
    question: 'Who are you doing this for?',
    review: 'Organisation',
    placeholder: 'Company, team — or nobody',
    kind: 'input',
    type: 'text',
    autocomplete: 'organization',
    required: false,
  },
  {
    id: 'topic',
    question: 'What brings you here?',
    review: "What it's about",
    kind: 'options',
    required: true,
    options: [
      'Cyber governance & strategy',
      'Portfolio, PMO & prioritisation',
      'Security due diligence on a deal',
      'Proposals, scoping & pricing',
      'A role or a collaboration',
      'Just saying hello',
    ],
  },
  {
    id: 'message',
    question: 'What should I know before we talk?',
    review: 'The message',
    placeholder: "Hi Nacho, we're trying to…",
    kind: 'textarea',
    required: true,
  },
];

/** Zero-padded, the way every number on the page is written. */
export const pad = (n: number) => String(n).padStart(2, '0');

export const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
