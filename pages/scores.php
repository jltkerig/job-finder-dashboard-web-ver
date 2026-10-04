<?php
// How the credibility scores are worked out. The text is the desktop Job Finder's templates/credibility-scores.html.

declare(strict_types=1);

$title = 'Credibility Scores';
$page = 'scores';
$subtitle = 'How Job Finder estimates its scores';
require APP_ROOT . '/templates/_top.php';
?>
<main>
  <div class="company-section">
    <article class="settings-panel score-guide">
  <p class="score-guide-intro">Career Credibility and USA Credibility are evidence scores, displayed from 0% to 100%. Internally, Job Finder stores each as 0–10 and multiplies by ten for display. A score is not the probability that a job is real, open, or suitable for you.
      </p>
  <nav class="score-guide-nav" aria-label="Scoring sections"><a href="#career-rules">Career rules</a><a href="#usa-rules">USA rules</a><a href="#what-scores-mean">What scores mean</a><a href="#future-checks">Future checks</a>
      </nav>
  <section id="career-rules">
        <h2>Career Credibility
        </h2>
    <p>Job Finder scores the selected career page’s URL, title, headings, visible text, and links. It checks up to eight discovered candidate pages and chooses the one with the highest Career Credibility score. These are the rules currently used:
        </p>
    <table class="score-rules">
          <thead>
            <tr><th>Evidence</th><th>Raw points</th><th>Displayed value</th>
            </tr>
          </thead>
          <tbody>
      <tr><td>Career wording such as “careers,” “open positions,” or “apply now” in the URL, title, or headings</td><td>3–4, capped at 4</td><td>+30–40%</td>
            </tr>
      <tr><td>If none is in those prominent places, career wording in page text</td><td>Up to 2 instead</td><td>Up to +20%</td>
            </tr>
      <tr><td>Supporting wording such as “jobs,” “hiring,” or “opportunities”</td><td>+1</td><td>+10%</td>
            </tr>
      <tr><td>At least one job or career link</td><td>+1</td><td>+10%</td>
            </tr>
      <tr><td>At least one “apply” link</td><td>+2</td><td>+20%</td>
            </tr>
      <tr><td>At least one link to a recognized applicant tracking site</td><td>+2</td><td>+20%</td>
            </tr>
      <tr><td>Non-hiring wording such as “unemployment benefits” or “file a claim”</td><td>−2 per matching phrase, up to −5</td><td>Up to −50%</td>
            </tr>
    </tbody>
        </table>
    <p>The raw total is clamped to 0–10. A page in a financial-aid or work-study section with a general student-employment heading and no structured <code>JobPosting</code> is skipped. An unresolved directory or marketplace result is also skipped. These checks are separate from the numerical score.
        </p>
  </section>
  <section id="usa-rules">
        <h2>USA Credibility
        </h2>
    <p>Job Finder reads the search result, landing page, selected career page, and some supporting pages. It keeps the <strong>highest single page score</strong>; it does not add points from different pages together.
        </p>
    <table class="score-rules">
          <thead>
            <tr><th>Evidence on a page</th><th>Raw points</th><th>Displayed value</th>
            </tr>
          </thead>
          <tbody>
      <tr><td>“United States,” “USA,” or a U.S. abbreviation</td><td>+4</td><td>+40%</td>
            </tr>
      <tr><td>A U.S. state name or abbreviation in a recognized text pattern</td><td>+3</td><td>+30%</td>
            </tr>
      <tr><td>If no state is found, an explicit U.S. remote indication</td><td>+2 instead</td><td>+20%</td>
            </tr>
      <tr><td>A five-digit ZIP code pattern</td><td>+1</td><td>+10%</td>
            </tr>
      <tr><td>Relevant structured address or job-location data</td><td>+2 when found</td><td>+20%</td>
            </tr>
      <tr><td>An official <code>.edu</code> page containing a city, state, and ZIP address</td><td>Minimum score of 8</td><td>At least 80%</td>
            </tr>
    </tbody>
        </table>
    <p>The raw total is clamped to 0–10. The <code>.edu</code> rule raises the location evidence; it does not establish that the page contains an eligible opening. A company’s U.S. office or footer address may differ from the job’s location, so check the listing itself.
        </p>
  </section>
  <section id="what-scores-mean">
        <h2>What the Percentages Mean
        </h2>
    <p><strong>Low:</strong> 0–40% · <strong>Medium:</strong> 50–70% · <strong>High:</strong> 80–100%. Scores change in 10% steps because they are currently stored on a ten-point scale. For a result to count toward the search’s “Passed” total, a web result must be an individual matching posting with Career Credibility of at least 30%; when the USA-only setting is on, USA Credibility must also be at least 50%. Lower scoring individual leads may remain visible for review until 10 jobs qualify.
        </p>
    <p>Current limits: career links in site navigation may affect the displayed score, but the search checks for a matching individual posting before counting the result. Location text can still come from a company-wide page. Remote job feed results (Remote OK, Remotive, We Work Remotely) use a separate, limited rule: 60% Career Credibility indicates a recent provider listing, not an independently checked employer page; USA Credibility is 60% when the location explicitly says USA or United States, and 50% when it says Worldwide, Anywhere, or Global. The app does not yet consult external business registries. A high percentage is an evidence summary, not an independent guarantee.
        </p>
  </section>
  <section id="future-checks">
        <h2>Possible Future Verification Sources
        </h2>
    <p><strong>These sources do not currently affect the score.</strong> Any future match should compare organization name and location, record the source and check date, and avoid treating a missing record as evidence against a small company.
        </p>
    <ul>
      <li><a href="https://egov.maryland.gov/businessexpress/entitysearch" target="_blank" rel="noopener noreferrer">State business registries</a> can support an entity’s registered name and jurisdiction; a registration does not verify a job opening.
          </li>
      <li><a href="https://www.sec.gov/search-filings" target="_blank" rel="noopener noreferrer">SEC EDGAR</a> can support the identity of a public company, while <a href="https://www.irs.gov/charities-non-profits/search-for-tax-exempt-organizations" target="_blank" rel="noopener noreferrer">IRS nonprofit records</a> can support a tax-exempt organization’s identity. They cover different subsets of employers.
          </li>
      <li><a href="https://sam.gov/entity-information" target="_blank" rel="noopener noreferrer">SAM.gov</a> can corroborate an entity that does business with the federal government; absence there is normal for many employers.
          </li>
      <li><a href="https://banks.data.fdic.gov/bankfind-suite" target="_blank" rel="noopener noreferrer">FDIC BankFind</a> can corroborate a bank’s name, website, and U.S. locations. <a href="https://npiregistry.cms.hhs.gov/" target="_blank" rel="noopener noreferrer">NPPES</a> can corroborate a healthcare provider’s registered details; an NPI does not verify licensure.
          </li>
      <li><a href="https://support.google.com/business/answer/2911778" target="_blank" rel="noopener noreferrer">Google Business Profiles</a> and <a href="https://business.yelp.com/products/business-page/" target="_blank" rel="noopener noreferrer">Yelp business pages</a> can offer a second name and address for a local business. They should carry little weight: a profile does not prove an active job or where the position is based.
          </li>
    </ul>
  </section>
  <a class="bordered-button secondary-action" href="/">Back to Search</a>
    </article>
  </div>
</main>
<?php require APP_ROOT . '/templates/_bottom.php';
