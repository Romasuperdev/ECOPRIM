/*
 * Les graphiques du tableau de bord — le seul module qui importe recharts (~400 ko).
 * DashboardPage le charge à la demande (lazy), APRÈS le bandeau et les tuiles : l'accueil
 * s'affiche sans attendre la bibliothèque. Le cadre des cartes (CarteGraphique) vit à part
 * pour la même raison.
 */
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
  fontWeight: 600,
  boxShadow: 'var(--shadow)',
}

// Les libellés d'axe portent de l'information chiffrée : ils sont lus, pas survolés.
// Ils prennent donc la couleur du texte courant, pas la couleur secondaire.
const AXE = { fill: 'var(--text)', fontSize: 12, fontWeight: 600 }

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
          <span className="text-xl font-extrabold text-heading">
            {valeur === null || valeur === undefined ? '—' : `${valeur}%`}
          </span>
        </div>
      </div>
      <p className="mt-2 text-center text-sm font-bold text-heading">{libelle}</p>
      {/* Le détail du calcul doit rester lisible : c'est lui qui rend le
          pourcentage vérifiable. Il passe donc en couleur de texte courante. */}
      {detail && <p className="text-center text-xs font-medium" style={{ color: 'var(--text)' }}>{detail}</p>}
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
          <span className="text-2xl font-extrabold text-heading">{total}</span>
          <span className="text-xs font-semibold text-muted">élèves</span>
        </div>
      </div>
      <ul className="mt-4 w-full min-w-0 space-y-1.5 sm:ml-5 sm:mt-0">
        {donnees.map((d, i) => (
          <li key={d[cleNom]} className="flex items-center gap-2 text-sm">
            <span
              className="h-2.5 w-2.5 shrink-0 rounded-full"
              style={{ background: COULEURS_SERIES[i % COULEURS_SERIES.length] }}
            />
            <span className="min-w-0 flex-1 truncate font-medium text-heading">{d[cleNom]}</span>
            <span className="font-bold text-heading">{d[cleValeur]}</span>
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
        {/* Par défaut recharts colore CHAQUE libellé de légende avec la couleur de sa
            série : « Transferts » en orange sur blanc tombait à 2,1:1, illisible. La
            pastille suffit à identifier la série ; le texte reprend la couleur courante. */}
        <Legend
          wrapperStyle={{ fontSize: '0.75rem', fontWeight: 600 }}
          iconType="circle"
          iconSize={8}
          formatter={(valeur) => <span style={{ color: 'var(--text)' }}>{valeur}</span>}
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
