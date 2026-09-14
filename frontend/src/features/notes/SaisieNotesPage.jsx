import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Check, Info, Save } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import { fetchAllClasses, fetchMatieres } from '../reference/referenceApi'
import { enregistrerFeuille, fetchFeuille, fetchStructureNotes } from './saisieNotesApi'

const TYPES = ['Devoir', 'Interrogation', 'Composition', 'Examen']
const SESSIONS = ['S1', 'S2', 'S3']

/**
 * Feuille de notes — la saisie, enfin ouverte.
 *
 * L'écran Notes ne savait que lire : il interroge V_NOTECLASSE, qui est une vue. Ici on
 * écrit dans les vraies tables. On choisit l'évaluation en haut, la classe entière apparaît,
 * on note, on enregistre une fois.
 *
 * La structure de ces tables d'ECONOMAT n'étant pas documentée, le serveur la reconnaît à
 * l'exécution. S'il n'y parvient pas, la saisie reste fermée et l'écran dit ce qui manque —
 * mieux vaut une saisie indisponible qu'une ligne fausse en production.
 */
export default function SaisieNotesPage() {
  const queryClient = useQueryClient()
  const [criteres, setCriteres] = useState({
    classe: '', matiere: '', session: 'S1', type: 'Devoir',
    libelle: '', date: '', coefficient: 1, bareme: 20,
  })
  const [saisie, setSaisie] = useState({})
  const [message, setMessage] = useState(null)

  const { data: structure } = useQuery({ queryKey: ['notes', 'structure'], queryFn: fetchStructureNotes, retry: false })
  const { data: classes } = useQuery({ queryKey: ['classes', 'all'], queryFn: fetchAllClasses })
  const { data: matieres } = useQuery({ queryKey: ['matieres', 'all'], queryFn: fetchMatieres })

  const pret = Boolean(criteres.classe && criteres.matiere && criteres.session)
  const ouvert = structure ? structure.saisie_possible !== false : true

  const { data: feuille, isFetching } = useQuery({
    queryKey: ['notes', 'feuille', criteres],
    queryFn: () => fetchFeuille(criteres),
    enabled: pret && ouvert,
    retry: false,
  })

  const eleves = useMemo(() => feuille?.eleves ?? [], [feuille])
  const bareme = Number(criteres.bareme) || 20

  // Les notes déjà enregistrées repeuplent la feuille : on corrige, on ne resaisit pas tout.
  // L'ajustement se fait pendant le rendu et non dans un effet : changer de feuille doit
  // remplacer la saisie en cours d'un seul coup, sans passage par un état intermédiaire où
  // les notes de la classe précédente s'afficheraient encore.
  const [feuilleAffichee, setFeuilleAffichee] = useState(null)
  const cleFeuille = feuille ? JSON.stringify([criteres, feuille.entete, eleves.length]) : null

  if (cleFeuille && cleFeuille !== feuilleAffichee) {
    setFeuilleAffichee(cleFeuille)
    setSaisie(Object.fromEntries(eleves.map((e) => [e.matricule, {
      note: e.note ?? '',
      appreciation: e.appreciation ?? '',
      absent: Boolean(e.absent),
    }])))
  }

  const champ = (k, v) => { setMessage(null); setCriteres((c) => ({ ...c, [k]: v })) }
  const cellule = (matricule, k, v) =>
    setSaisie((s) => ({ ...s, [matricule]: { ...s[matricule], [k]: v } }))

  // Ce qui empêche d'enregistrer, dit avant de cliquer plutôt qu'après.
  const invalides = useMemo(
    () => eleves.filter((e) => {
      const l = saisie[e.matricule]
      if (!l || l.absent || l.note === '' || l.note === null) return false
      const n = Number(l.note)
      return Number.isNaN(n) || n < 0 || n > bareme
    }).map((e) => e.matricule),
    [eleves, saisie, bareme],
  )

  const renseignees = eleves.filter((e) => {
    const l = saisie[e.matricule]
    return l && (l.absent || (l.note !== '' && l.note !== null))
  }).length

  const enregistrement = useMutation({
    mutationFn: () => enregistrerFeuille({
      criteres,
      notes: eleves
        .filter((e) => {
          const l = saisie[e.matricule]
          return l && (l.absent || (l.note !== '' && l.note !== null))
        })
        .map((e) => ({
          matricule: e.matricule,
          note: saisie[e.matricule].absent ? null : Number(saisie[e.matricule].note),
          appreciation: saisie[e.matricule].appreciation || null,
          absent: saisie[e.matricule].absent,
        })),
    }),
    onSuccess: (r) => {
      setMessage({ ton: 'ok', texte: `${r.creees} note(s) enregistrée(s), ${r.modifiees} corrigée(s).` })
      queryClient.invalidateQueries({ queryKey: ['notes'] })
    },
    onError: (e) => setMessage({
      ton: 'erreur',
      texte: e?.response?.data?.message
        ?? Object.values(e?.response?.data?.errors ?? {})[0]?.[0]
        ?? 'Enregistrement impossible.',
    }),
  })

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Saisie des notes</h1>
        <p className="mt-1 text-sm text-slate-500">
          Une feuille par évaluation. Les notes sont écrites dans ECONOMAT et alimentent
          aussitôt les moyennes et les bulletins.
        </p>
      </div>

      {/* Fermé par défaut : on explique pourquoi, on ne laisse pas un écran muet. */}
      {structure && !structure.saisie_possible && (
        <div className="mb-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          <AlertTriangle size={16} className="mt-0.5 shrink-0" />
          <div>
            <p className="font-medium">Saisie indisponible sur cette base.</p>
            <p className="mt-1">
              NEXORA n’a pas reconnu la structure des tables de notes et refuse d’y écrire à
              l’aveugle. Rôles non identifiés :{' '}
              {[...(structure.entete?.roles_manquants ?? []), ...(structure.detail?.roles_manquants ?? [])].join(', ') || '—'}.
            </p>
          </div>
        </div>
      )}

      <div className="mb-4 grid gap-3 rounded-xl border border-slate-200 bg-white p-4 md:grid-cols-4">
        <Select label="Classe" value={criteres.classe} onChange={(e) => champ('classe', e.target.value)}>
          <option value="">— Sélectionner —</option>
          {classes?.map((c) => <option key={c.id} value={c.code}>{c.nom}</option>)}
        </Select>
        <Select label="Matière" value={criteres.matiere} onChange={(e) => champ('matiere', e.target.value)}>
          <option value="">— Sélectionner —</option>
          {matieres?.map((m) => <option key={m.id} value={m.code}>{m.libelle}</option>)}
        </Select>
        <Select label="Session" value={criteres.session} onChange={(e) => champ('session', e.target.value)}>
          {SESSIONS.map((s) => <option key={s} value={s}>{s}</option>)}
        </Select>
        <Select label="Type" value={criteres.type} onChange={(e) => champ('type', e.target.value)}>
          {TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
        </Select>
        <Input label="Intitulé (optionnel)" placeholder="Devoir n°2…" value={criteres.libelle}
               onChange={(e) => champ('libelle', e.target.value)} />
        <Input label="Date" type="date" value={criteres.date} onChange={(e) => champ('date', e.target.value)} />
        <Input label="Coefficient" type="number" min="0" step="0.5" value={criteres.coefficient}
               onChange={(e) => champ('coefficient', e.target.value)} />
        <Input label="Barème" type="number" min="1" step="1" value={criteres.bareme}
               onChange={(e) => champ('bareme', e.target.value)} />
      </div>

      <div className="mb-4 flex items-start gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
        <Info size={16} className="mt-0.5 shrink-0" />
        <span>
          Deux saisies sur la même classe, matière, session, type et intitulé sont la
          <strong> même feuille</strong> : la seconde corrige la première au lieu de la
          doubler. Pour une seconde évaluation, changez l’intitulé.
        </span>
      </div>

      {message && (
        <div className={`mb-4 flex items-start gap-2 rounded-xl border px-4 py-3 text-sm ${
          message.ton === 'ok'
            ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
            : 'border-red-200 bg-red-50 text-red-700'
        }`}>
          {message.ton === 'ok' ? <Check size={16} className="mt-0.5 shrink-0" /> : <AlertTriangle size={16} className="mt-0.5 shrink-0" />}
          <span>{message.texte}</span>
        </div>
      )}

      {!pret && <p className="text-slate-400">Choisissez une classe, une matière et une session.</p>}

      {pret && (
        <>
          <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
                <tr>
                  <th className="px-4 py-3 font-medium">Élève</th>
                  <th className="px-4 py-3 font-medium">Matricule</th>
                  <th className="px-4 py-3 font-medium w-32">Note / {bareme}</th>
                  <th className="px-4 py-3 font-medium w-24">Absent</th>
                  <th className="px-4 py-3 font-medium">Appréciation</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {isFetching && (
                  <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>
                )}
                {!isFetching && eleves.length === 0 && (
                  <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">Aucun élève dans cette classe.</td></tr>
                )}
                {eleves.map((e) => {
                  const l = saisie[e.matricule] ?? { note: '', appreciation: '', absent: false }
                  const faux = invalides.includes(e.matricule)

                  return (
                    <tr key={e.matricule} className="hover:bg-slate-50">
                      <td className="px-4 py-2 font-medium text-slate-800">{`${e.nom} ${e.prenom}`.trim()}</td>
                      <td className="px-4 py-2 text-slate-500">{e.matricule}</td>
                      <td className="px-4 py-2">
                        <input
                          type="number" min="0" max={bareme} step="0.25" disabled={l.absent}
                          value={l.note}
                          onChange={(ev) => cellule(e.matricule, 'note', ev.target.value)}
                          className={`w-24 rounded-lg border px-2 py-1 text-sm disabled:bg-slate-100 ${
                            faux ? 'border-red-400 bg-red-50' : 'border-slate-200'
                          }`}
                        />
                      </td>
                      <td className="px-4 py-2">
                        <input
                          type="checkbox" checked={l.absent}
                          onChange={(ev) => cellule(e.matricule, 'absent', ev.target.checked)}
                          className="h-4 w-4 rounded border-slate-300"
                        />
                      </td>
                      <td className="px-4 py-2">
                        <input
                          type="text" maxLength={255} value={l.appreciation}
                          onChange={(ev) => cellule(e.matricule, 'appreciation', ev.target.value)}
                          className="w-full rounded-lg border border-slate-200 px-2 py-1 text-sm"
                          placeholder="—"
                        />
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          <div className="mt-4 flex items-center justify-between gap-3">
            <p className="text-xs text-slate-400">
              {renseignees} élève{renseignees > 1 ? 's' : ''} sur {eleves.length} renseigné{renseignees > 1 ? 's' : ''}.
              {invalides.length > 0 && (
                <span className="ml-1 text-red-600">
                  {invalides.length} note{invalides.length > 1 ? 's' : ''} hors barème.
                </span>
              )}
            </p>
            <Button
              onClick={() => enregistrement.mutate()}
              disabled={!ouvert || renseignees === 0 || invalides.length > 0 || enregistrement.isPending}
            >
              <Save size={16} className="mr-1.5" />
              {enregistrement.isPending ? 'Enregistrement…' : 'Enregistrer la feuille'}
            </Button>
          </div>
        </>
      )}
    </div>
  )
}
