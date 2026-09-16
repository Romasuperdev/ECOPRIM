import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Pie,
  PieChart,
  PolarAngleAxis,
  RadialBar,
  RadialBarChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'

// Les couleurs passent par les variables du design system, jamais par des valeurs
// littérales : les graphiques suivent ainsi le mode clair/sombre comme le reste.
const COULEURS_SERIES = [
  'var(--chart-1)',
  'var(--chart-2)',
  'var(--chart-3)',
  'var(--chart-4)',
  'var(--chart-5)',
]

const STYLE_INFOBULLE = {
  background: 'var(--popover)',
  border: '1px solid var(--border)',
  borderRadius: '0.75rem',
  color: 'var(--text)',
  fontSize: '0.8rem',
  boxShadow: 'var(--shadow)',
}

const AXE = { fill: 'var(--muted-text)', fontSize: 11 }

/**
 * Cadre commun à tous les blocs : titre, actions facultatives, et surtout un état
 * vide explicite. Un graphique sans données doit le dire — un cadre vide laisse
 * croire à une panne, alors que la vraie réponse est « rien n'a encore été saisi ».
 */
/**
 * @param {boolean} hauteurFixe  Les graphiques recharts (`ResponsiveContainer`) exigent
 *   un conteneur de hauteur définie. Les autres blocs, eux, doivent pouvoir grandir :
 *   sur mobile l'anneau passe au-dessus de sa légende et déborderait d'une hauteur figée.
 */
export function CarteGraphique({ titre, actions, vide, messageVide, hauteur = 260, hauteurFixe = false, children }) {
  return (
    <div className="card flex flex-col rounded-2xl p-5">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-base font-semibold text-heading">{titre}</h2>
        {actions}
      </div>
      {vide ? (
        <div
          className="flex flex-1 items-center justify-center rounded-xl border border-dashed px-4 text-center text-sm text-muted"
          style={{ minHeight: hauteur }}
        >
          {messageVide ?? 'Pas encore de données pour cette année.'}
        </div>
      ) : (
        <div style={hauteurFixe ? { height: hauteur } : { minHeight: hauteur }}>{children}</div>
      )}
    </div>
  )
}

/**
 * Jauge circulaire. `detail` porte le calcul réel (« 2 absences sur 1 467 journées »)
 * : un pourcentage seul n'est pas vérifiable, et celui-ci a déjà été faux une fois.
 */
export function Jauge({ valeur, couleur, libelle, detail }) {
  const donnees = [{ valeur: Math.max(0, Math.min(100, valeur ?? 0)) }]

  return (
    <div className="flex flex-col items-center">
      <div className="relative h-24 w-24 sm:h-28 sm:w-28">
        <ResponsiveContainer width="100%" height="100%">
          <RadialBarChart
            data={donnees}
            innerRadius="72%"
            outerRadius="100%"
            startAngle={90}
            endAngle={-270}
            barSize={10}
          >
            <PolarAngleAxis type="number" domain={[0, 100]} angleAxisId={0} tick={false} />
            <RadialBar
              dataKey="valeur"
              angleAxisId={0}
              cornerRadius={10}
              fill={couleur}
              background={{ fill: 'var(--surface-2)' }}
              isAnimationActive={false}
            />
          </RadialBarChart>
        </ResponsiveContainer>
        <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
          <span className="text-lg font-bold text-heading">
            {valeur === null || valeur === undefined ? '—' : `${valeur}%`}
          </span>
        </div>
      </div>
      <p className="mt-2 text-center text-xs font-medium text-heading">{libelle}</p>
      {detail && <p className="text-center text-[11px] text-muted">{detail}</p>}
    </div>
  )
}

/** Anneau de répartition (effectif par niveau). */
export function Anneau({ donnees, cleValeur, cleNom }) {
  const total = donnees.reduce((somme, d) => somme + (d[cleValeur] ?? 0), 0)

  return (
    <div className="flex h-full flex-col items-center sm:flex-row">
      <div className="relative h-40 w-40 shrink-0">
        <ResponsiveContainer width="100%" height="100%">
          <PieChart>
            <Pie
              data={donnees}
              dataKey={cleValeur}
              nameKey={cleNom}
              innerRadius="62%"
              outerRadius="100%"
              paddingAngle={2}
              stroke="none"
              isAnimationActive={false}
            >
              {donnees.map((d, i) => (
                <Cell key={d[cleNom]} fill={COULEURS_SERIES[i % COULEURS_SERIES.length]} />
              ))}
            </Pie>
            <Tooltip contentStyle={STYLE_INFOBULLE} />
          </PieChart>
        </ResponsiveContainer>
        <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
          <span className="text-xl font-bold text-heading">{total}</span>
          <span className="text-[11px] text-muted">élèves</span>
        </div>
      </div>
      <ul className="mt-4 w-full min-w-0 space-y-1.5 sm:ml-5 sm:mt-0">
        {donnees.map((d, i) => (
          <li key={d[cleNom]} className="flex items-center gap-2 text-sm">
            <span
              className="h-2.5 w-2.5 shrink-0 rounded-full"
              style={{ background: COULEURS_SERIES[i % COULEURS_SERIES.length] }}
            />
            <span className="min-w-0 flex-1 truncate text-muted">{d[cleNom]}</span>
            <span className="font-semibold text-heading">{d[cleValeur]}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}

/** Histogramme empilé mois par mois. `series` : [{ cle, libelle, couleur }]. */
export function HistogrammeMensuel({ donnees, series }) {
  return (
    <ResponsiveContainer width="100%" height="100%">
      <BarChart data={donnees} margin={{ top: 4, right: 4, left: -20, bottom: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
        <XAxis dataKey="libelle" tick={AXE} axisLine={false} tickLine={false} />
        <YAxis tick={AXE} axisLine={false} tickLine={false} allowDecimals={false} />
        <Tooltip contentStyle={STYLE_INFOBULLE} cursor={{ fill: 'var(--surface-2)' }} />
        <Legend
          wrapperStyle={{ fontSize: '0.75rem', color: 'var(--muted-text)' }}
          iconType="circle"
          iconSize={8}
        />
        {/* maxBarSize : sans plafond, un seul mois de données donne une barre large
            comme tout le graphique, qui ne se lit plus comme une barre. */}
        {series.map((s) => (
          <Bar
            key={s.cle}
            dataKey={s.cle}
            name={s.libelle}
            stackId="a"
            fill={s.couleur}
            maxBarSize={48}
            isAnimationActive={false}
          />
        ))}
      </BarChart>
    </ResponsiveContainer>
  )
}
