/**
 * PASSAGES — the only file you need to edit.
 *
 * Each round pairs one excerpt from Matthew Ball's "The State of Video Gaming in 2026"
 * (Epyllion, PDF at matthewball.co) with one excerpt from Chris Zukowski's
 * "The optimistic view that indie games are in a golden age right now"
 * (howtomarketagame.com, Nov 4 2025), then asks students for a Claim–Evidence–Reasoning response.
 *
 * The `text` fields are left for you to fill: copy the excerpt from the source into the
 * quotes. Keep each excerpt to roughly 80–200 words so the two columns stay readable
 * side by side. Use \n for a paragraph break. Escape any double quotes as \".
 *
 * The `hint` tells you where to look and what the excerpt should show. Students never see hints.
 * Until you paste an excerpt, students see a note that it isn't loaded yet.
 *
 * Sources
 *   A = Ball.     Chapters: I (pp. 2–14), II (15–42), III (43–64), IV (65–88), V (89–157), VI (158–164), Sources (165–166)
 *   B = Zukowski. Section headings are quoted in each hint exactly as they appear on the page.
 */

const AUTHORS = {
  a: { name: 'Matthew Ball', work: 'The State of Video Gaming in 2026', stance: 'pessimistic' },
  b: { name: 'Chris Zukowski', work: 'The optimistic view that indie games are in a golden age right now', stance: 'optimistic' }
};

const PASSAGES = [
  {
    n: 1,
    theme: 'Is the games industry healthy?',
    a: {
      where: 'Ball, Ch. I–II',
      hint: 'The slides where 2025 content sales hit a record (about $195.6B) while private investment fell about 55%, plus his point that inflation-adjusted growth is roughly flat.',
      text: ''
    },
    b: {
      where: 'Zukowski, opening paragraphs',
      hint: 'From "It has been a rough couple of years..." through the paragraph that ends "...good times are here."',
      text: ''
    },
    prompt: 'Both authors accept that studios have closed and people have lost jobs. Make a claim about which author gives the more accurate picture of the industry\'s health. Use at least one piece of evidence from EACH passage, and explain why that evidence supports your claim.'
  },
  {
    n: 2,
    theme: 'Who is capturing the growth?',
    a: {
      where: 'Ball, Ch. II',
      hint: 'China at roughly 20% of global spend but ~38% of growth; Chinese publishers taking about half of all spending growth since 2019; Roblox taking share.',
      text: ''
    },
    b: {
      where: 'Zukowski, "The Great Conjunction Games" and "Friend-slop"',
      hint: 'The examples of tiny teams shipping fast: the co-op game built in about 8 weeks that passed 100,000 concurrent players, and the team that pivoted after 3 years on a different game.',
      text: ''
    },
    prompt: 'Ball says almost none of last year\'s growth went to Western developers. Zukowski shows small Western teams succeeding anyway. Make a claim about whether a small team in the U.S. can realistically capture growth today. Cite evidence from both passages and explain your reasoning.'
  },
  {
    n: 3,
    theme: 'What do players actually want?',
    a: {
      where: 'Ball, Ch. III',
      hint: 'The slides showing the top ten PC/console franchises are all more than ten years old and take about half of all engagement hours, alongside falling participation in the eight mature markets.',
      text: ''
    },
    b: {
      where: 'Zukowski, "Steam players want fun first"',
      hint: 'The whole short section: players will look past rough graphics for tight, fun, deep gameplay.',
      text: ''
    },
    prompt: 'Ball argues attention is flowing to old, giant franchises. Zukowski argues players are hungry for new, unpolished games. Make a claim about whether both can be true at the same time. Use evidence from each passage, and in your reasoning explain how the two claims fit together (or why they cannot).'
  },
  {
    n: 4,
    theme: 'Getting noticed',
    a: {
      where: 'Ball, Ch. II (mobile section)',
      hint: 'The share of spend and downloads going to new releases is at a decade low; user-acquisition costs crowd out discovery; "old giants strengthen."',
      text: ''
    },
    b: {
      where: 'Zukowski, "Marketing is super charged"',
      hint: 'The two examples that broke the usual Steam rules: a game with a very short store-page run that still gathered thousands of reviews, and a demo that went from a few hundred wishlists to about 20,000 during one festival.',
      text: ''
    },
    prompt: 'Notice which platform each author is describing. Make a claim about whether Ball and Zukowski actually disagree about how new games get discovered, or whether they are describing two different markets. Support it with evidence from both passages and explain your reasoning.'
  },
  {
    n: 5,
    theme: 'How long should a game take to make?',
    a: {
      where: 'Ball, Ch. I (and Ch. VI)',
      hint: 'The slides on retrenchment and margin struggles: rising development costs and publishers concentrating on fewer, bigger, longer projects.',
      text: ''
    },
    b: {
      where: 'Zukowski, "No more YEARS LONG developers SUFFERING for their ART"',
      hint: 'The argument against multi-year passion projects and for shipping small games in weeks or months, with the early id Software and Flash-portal comparison.',
      text: ''
    },
    prompt: 'Imagine you are planning your first commercial game after this class. Make a claim about how long that project should take. Use evidence from each passage and explain how each author\'s view of time and cost shaped your reasoning.'
  },
  {
    n: 6,
    theme: 'Is game development a job you can count on?',
    a: {
      where: 'Ball, layoffs slides (Ch. I)',
      hint: 'The four-year layoff totals: roughly 8,500 (2022), 10,500 (2023), 15,650 (2024), 9,200 (2025), about 44,000 in all.',
      text: ''
    },
    b: {
      where: 'Zukowski, "Aren\'t you just CHASING TRENDS?"',
      hint: 'The paragraphs about chasing trends so you can leave a day job, not go back to a big publisher, and earn a full-time living as an independent developer.',
      text: ''
    },
    prompt: 'Make a claim about what these two passages mean for a student deciding whether to pursue game development as a career. Cite evidence from both passages and explain your reasoning. Your reasoning should mention at least one skill or habit that would matter under BOTH authors\' versions of the future.'
  },
  {
    n: 7,
    theme: 'What counts as success?',
    a: {
      where: 'Ball, Ch. I–II (funding)',
      hint: 'Private funding down about 55% year over year; roughly five times fewer deals than in late 2021; the question of whether games are still investable.',
      text: ''
    },
    b: {
      where: 'Zukowski, "But what if the bubble pops..."',
      hint: 'His admission that this is not risk-free, followed by the list of what a developer gains even when a fast game fails.',
      text: ''
    },
    prompt: 'Both authors agree that making games is risky. Make a claim about which author offers a more useful definition of "success" for a beginner. Cite evidence from both passages and explain your reasoning.'
  },
  {
    n: 8,
    theme: 'How does each author know what he knows?',
    a: {
      where: 'Ball, Ch. VI and the Sources pages (165–166)',
      hint: 'His closing argument that there is no single "video game industry" but many, plus the kinds of data he relies on (market totals, spending, hours, funding).',
      text: ''
    },
    b: {
      where: 'Zukowski, "But Chris... Survivorship Bias!"',
      hint: 'His response to the survivorship-bias objection and his defense of studying winners as "positive deviance."',
      text: ''
    },
    prompt: 'Ball argues mostly from whole-market totals. Zukowski argues mostly from case studies of games that succeeded. Make a claim about which kind of evidence is more reliable for predicting YOUR outcome as a new developer. Use evidence from both passages, and in your reasoning name at least one weakness of the method you prefer.'
  }
];
