import { useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlertTriangle, ArrowRight, CheckCircle2, Download, FileSpreadsheet, Lock, Upload,
} from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import MessageRefus from '../../components/ui/MessageRefus'
import { useAuthStore } from '../../store/authStore'
import {
  analyserImport, appliquerImport, exporterJeux, fetchCatalogueEchanges, telechargerModele,
} from './echangesApi'

const messageErreur = (e) =>
  e?.response?.data?.errors?.fichier?.[0]
  ?? e?.response?.data?.errors?.jeton?.[0]
  ?? e?.response?.data?.errors?.jeu?.[0]
  ?? e?.response?.data?.message
  ?? 'Une erreur est survenue.'

/** Un compteur du rapport d'analyse : le chiffre d'abord, il se lit de loin. */
function Compteur({ valeur, libelle, couleur }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-card px-4 py-3">
      <div className="text-2xl font-extrabold leading-none" style={{ color: couleur }}>{valeur}</div>
      <div className="mt-1 text-xs font-bold uppercase tracking-wide text-muted">{libelle}</div>
    </div>
  )
}

function Etiquette({ action }) {
  // Par les rôles et non par des littéraux : ces trois étiquettes doivent rester lisibles
  // en mode sombre, où les fonds et les encres s'inversent.
  const styles = {
    creation: { fond: 'var(--success-bg)', encre: 'var(--success-text)', texte: 'Création' },
    modification: { fond: 'var(--info-bg)', encre: 'var(--info-text)', texte: 'Mise à jour' },
    rejet: { fond: 'var(--danger-bg)', encre: 'var(--danger-text)', texte: 'Écartée' },
  }[action]

  return (
    <span
      className="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-bold"
      style={{ background: styles.fond, color: styles.encre }}
    >
      {styles.texte}
    </span>
  )
}

/** Choix des jeux à exporter. Lecture seule : rien ici ne peut abîmer quoi que ce soit. */
function SectionExport({ catalogue }) {
  const [choisis, setChoisis] = useState([])
  const [refus, setRefus] = useState(null)

  const jeux = catalogue?.jeux ?? []
  const tout = choisis.length === 0

  const exporter = useMutation({
    mutationFn: () => exporterJeux(choisis),
    onError: (e) => setRefus(messageErreur(e)),
  })

  const basculer = (code) => setChoisis((c) =>
    c.includes(code) ? c.filter((x) => x !== code) : [...c, code])

  const lignes = tout
    ? jeux.reduce((somme, j) => somme + j.lignes, 0)
    : jeux.filter((j) => choisis.includes(j.code)).reduce((somme, j) => somme + j.lignes, 0)

  return (
    <section className="card rounded-2xl p-5">
      <header className="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="flex items-center gap-2 text-lg font-bold text-heading">
            <Download size={20} strokeWidth={2.5} style={{ color: 'var(--module-traitement)' }} />
            Exporter
          </h2>
          <p className="mt-1 text-sm font-medium text-muted">
            Un classeur, une feuille par jeu. Sans rien cocher, c’est toute l’année
            {catalogue?.annee ? ` ${catalogue.annee}` : ''} qui part.
          </p>
        </div>
        <Button
          variant="succes"
          disabled={exporter.isPending || lignes === 0}
          onClick={() => { setRefus(null); exporter.mutate() }}
        >
          <Download size={16} />
          {exporter.isPending ? 'Préparation…' : `Exporter ${lignes} ligne${lignes > 1 ? 's' : ''}`}
        </Button>
      </header>

      <MessageRefus message={refus} onFermer={() => setRefus(null)} />

      <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {jeux.map((jeu) => {
          const actif = choisis.includes(jeu.code)
          return (
            <label
              key={jeu.code}
              className="flex cursor-pointer items-start gap-3 rounded-xl border px-3 py-2.5 transition"
              style={{
                borderColor: actif ? 'var(--brand-accent)' : 'var(--border)',
                background: actif ? 'var(--surface-2)' : 'transparent',
              }}
            >
              <input
                type="checkbox"
                checked={actif}
                onChange={() => basculer(jeu.code)}
                className="mt-1 size-4 shrink-0 accent-[var(--brand-accent)]"
              />
              <span className="min-w-0">
                <span className="block text-sm font-bold text-heading">{jeu.libelle}</span>
                <span className="block text-xs font-semibold text-muted">
                  {jeu.lignes} ligne{jeu.lignes > 1 ? 's' : ''} · {jeu.colonnes.length} colonnes
                </span>
                {/* Une colonne absente d'ECONOMAT part vide : le dire vaut mieux que
                    laisser croire que la donnée n'existe pas. */}
                {jeu.colonnes_introuvables?.length > 0 && (
                  <span className="mt-1 block text-xs font-semibold" style={{ color: 'var(--warning-text)' }}>
                    Vides (absentes d’ECONOMAT) : {jeu.colonnes_introuvables.join(', ')}
                  </span>
                )}
              </span>
            </label>
          )
        })}
      </div>

      {choisis.length > 0 && (
        <button
          type="button"
          onClick={() => setChoisis([])}
          className="mt-3 text-sm font-semibold underline"
          style={{ color: 'var(--brand-accent)' }}
        >
          Tout décocher (exporter l’année entière)
        </button>
      )}
    </section>
  )
}

/** Le rapport d'analyse : ce qui se passerait, avant que quoi que ce soit ne se passe. */
function Rapport({ rapport, onAppliquer, onAnnuler, enCours }) {
  const aEcrire = rapport.creations + rapport.modifications

  return (
    <div className="mt-4 rounded-2xl border border-slate-200 bg-card p-5">
      <h3 className="mb-1 text-base font-bold text-heading">
        Rapport d’analyse — {rapport.jeu_libelle}
      </h3>
      <p className="mb-4 text-sm font-medium text-muted">
        {rapport.lignes} ligne{rapport.lignes > 1 ? 's' : ''} lue
        {rapport.lignes > 1 ? 's' : ''}. Rien n’a encore été écrit.
      </p>

      <div className="grid grid-cols-3 gap-3">
        <Compteur valeur={rapport.creations} libelle="Créations" couleur="var(--success)" />
        <Compteur valeur={rapport.modifications} libelle="Mises à jour" couleur="var(--brand-accent)" />
        <Compteur valeur={rapport.rejets} libelle="Écartées" couleur="var(--danger)" />
      </div>

      {rapport.colonnes_inconnues.length > 0 && (
        <p className="mt-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
          <AlertTriangle size={16} className="mt-0.5 shrink-0" />
          <span>
            Colonnes non reconnues, donc ignorées : <strong>{rapport.colonnes_inconnues.join(', ')}</strong>.
          </span>
        </p>
      )}

      {rapport.colonnes_absentes.length > 0 && (
        <p className="mt-3 text-sm font-medium text-muted">
          Colonnes absentes du fichier (les valeurs existantes ne seront pas touchées) :{' '}
          {rapport.colonnes_absentes.join(', ')}.
        </p>
      )}

      {rapport.apercu.length > 0 && (
        <div className="mt-4 overflow-x-auto rounded-xl border border-slate-200">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-muted">
              <tr>
                <th className="px-3 py-2 font-bold">Ligne</th>
                <th className="px-3 py-2 font-bold">Sort</th>
                <th className="px-3 py-2 font-bold">Contenu</th>
                <th className="px-3 py-2 font-bold">Motif</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rapport.apercu.map((l) => (
                <tr key={l.ligne}>
                  <td className="px-3 py-2 font-semibold text-muted">{l.ligne}</td>
                  <td className="px-3 py-2"><Etiquette action={l.action} /></td>
                  <td className="px-3 py-2 font-semibold text-heading">{l.apercu || '—'}</td>
                  <td className="px-3 py-2 text-muted">{l.motif ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {rapport.lignes > rapport.apercu.length && (
            <p className="border-t border-slate-200 px-3 py-2 text-xs font-semibold text-muted">
              {rapport.apercu.length} lignes affichées sur {rapport.lignes} — les lignes écartées
              d’abord.
            </p>
          )}
        </div>
      )}

      <div className="mt-5 flex flex-wrap items-center gap-3">
        <Button
          variant="attention"
          disabled={enCours || aEcrire === 0}
          onClick={onAppliquer}
        >
          <ArrowRight size={16} />
          {enCours
            ? 'Application…'
            : aEcrire === 0
              ? 'Rien à appliquer'
              : `Appliquer : ${rapport.creations} création${rapport.creations > 1 ? 's' : ''}, ${rapport.modifications} mise${rapport.modifications > 1 ? 's' : ''} à jour`}
        </Button>
        <Button variant="outline" disabled={enCours} onClick={onAnnuler}>Annuler</Button>
        <span className="text-xs font-semibold text-muted">
          Les lignes écartées ne seront pas importées. Rien n’est jamais supprimé.
        </span>
      </div>
    </div>
  )
}

function SectionImport({ catalogue }) {
  const qc = useQueryClient()
  const champFichier = useRef(null)
  const [jeu, setJeu] = useState('')
  const [bareme, setBareme] = useState('20')
  const [fichier, setFichier] = useState(null)
  const [rapport, setRapport] = useState(null)
  const [bilan, setBilan] = useState(null)
  const [refus, setRefus] = useState(null)

  const importables = useMemo(
    () => (catalogue?.jeux ?? []).filter((j) => j.importable),
    [catalogue],
  )
  const fermes = useMemo(
    () => (catalogue?.jeux ?? []).filter((j) => !j.importable),
    [catalogue],
  )

  const reinitialiser = () => {
    setFichier(null)
    setRapport(null)
    if (champFichier.current) champFichier.current.value = ''
  }

  const analyser = useMutation({
    mutationFn: () => analyserImport(jeu, fichier, jeu === 'notes' ? { bareme } : {}),
    onSuccess: (data) => { setRapport(data); setBilan(null); setRefus(null) },
    onError: (e) => { setRapport(null); setRefus(messageErreur(e)) },
  })

  const appliquer = useMutation({
    mutationFn: () => appliquerImport(rapport.jeton),
    onSuccess: (data) => {
      setBilan(data)
      reinitialiser()
      // Le catalogue affiche des volumes : ils viennent de changer.
      qc.invalidateQueries({ queryKey: ['echanges-catalogue'] })
    },
    onError: (e) => setRefus(messageErreur(e)),
  })

  if (catalogue?.annee_cloturee) {
    return (
      <section className="card rounded-2xl p-5">
        <h2 className="flex items-center gap-2 text-lg font-bold text-heading">
          <Upload size={20} strokeWidth={2.5} style={{ color: 'var(--module-programme)' }} />
          Importer
        </h2>
        <p className="mt-3 flex items-start gap-2 rounded-xl border border-slate-200 bg-card px-4 py-3 text-sm font-medium text-muted">
          <Lock size={16} className="mt-0.5 shrink-0" />
          <span>
            L’année <strong>{catalogue.annee}</strong> est clôturée : plus rien ne peut y être
            importé. L’export, lui, reste ouvert — c’est justement une année close qu’on archive.
          </span>
        </p>
      </section>
    )
  }

  return (
    <section className="card rounded-2xl p-5">
      <header className="mb-4">
        <h2 className="flex items-center gap-2 text-lg font-bold text-heading">
          <Upload size={20} strokeWidth={2.5} style={{ color: 'var(--module-programme)' }} />
          Importer
        </h2>
        <p className="mt-1 text-sm font-medium text-muted">
          En deux temps : on dépose, on lit le rapport, puis on applique. Un import ne supprime
          jamais rien — un élève absent du fichier reste inscrit.
        </p>
      </header>

      <MessageRefus message={refus} onFermer={() => setRefus(null)} />

      {bilan && (
        <div
          className="mb-4 flex items-start gap-2 rounded-xl border px-4 py-3 text-sm font-medium"
          style={{
            borderColor: 'var(--success-border)',
            background: 'var(--success-bg)',
            color: 'var(--success-text)',
          }}
        >
          <CheckCircle2 size={16} className="mt-0.5 shrink-0" />
          <span>
            Import terminé : <strong>{bilan.bilan.creees}</strong> création
            {bilan.bilan.creees > 1 ? 's' : ''}, <strong>{bilan.bilan.modifiees}</strong> mise
            {bilan.bilan.modifiees > 1 ? 's' : ''} à jour
            {bilan.bilan.echecs.length > 0 && (
              <>
                {' '}— <strong>{bilan.bilan.echecs.length}</strong> ligne
                {bilan.bilan.echecs.length > 1 ? 's' : ''} en échec :{' '}
                {bilan.bilan.echecs.slice(0, 3).map((e) => `ligne ${e.ligne} (${e.motif})`).join(' ; ')}
              </>
            )}
            .
          </span>
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        <Select
          label="Que voulez-vous importer ?"
          value={jeu}
          onChange={(e) => { setJeu(e.target.value); reinitialiser(); setBilan(null) }}
        >
          <option value="">— Choisir —</option>
          {importables.map((j) => (
            <option key={j.code} value={j.code}>{j.libelle}</option>
          ))}
        </Select>

        {jeu === 'notes' && (
          <Input
            label="Barème des notes du fichier"
            type="number"
            min="1"
            max="100"
            value={bareme}
            onChange={(e) => setBareme(e.target.value)}
          />
        )}
      </div>

      {jeu && (
        <>
          <div className="mt-4 flex flex-wrap items-center gap-3">
            <input
              ref={champFichier}
              type="file"
              accept=".xlsx,.xls,.csv"
              onChange={(e) => { setFichier(e.target.files?.[0] ?? null); setRapport(null) }}
              className="field field--dense max-w-sm"
            />
            <Button
              disabled={!fichier || analyser.isPending}
              onClick={() => { setRefus(null); analyser.mutate() }}
            >
              <FileSpreadsheet size={16} />
              {analyser.isPending ? 'Analyse…' : 'Analyser le fichier'}
            </Button>
            <button
              type="button"
              onClick={() => telechargerModele(jeu)}
              className="text-sm font-semibold underline"
              style={{ color: 'var(--brand-accent)' }}
            >
              Télécharger le modèle vierge
            </button>
          </div>

          <p className="mt-2 text-xs font-semibold text-muted">
            .xlsx, .xls ou .csv, jusqu’à {catalogue?.lignes_max_import} lignes. La casse et les
            accents des en-têtes n’ont pas d’importance ; une colonne inconnue est signalée, pas
            devinée.
          </p>
        </>
      )}

      {rapport && (
        <Rapport
          rapport={rapport}
          enCours={appliquer.isPending}
          onAppliquer={() => { setRefus(null); appliquer.mutate() }}
          onAnnuler={reinitialiser}
        />
      )}

      {fermes.length > 0 && (
        <details className="mt-5 text-sm">
          <summary className="cursor-pointer font-bold text-heading">
            Pourquoi {fermes.length} jeux s’exportent mais ne s’importent pas
          </summary>
          <ul className="mt-2 space-y-2">
            {fermes.map((j) => (
              <li key={j.code} className="text-muted">
                <span className="font-bold text-heading">{j.libelle}</span> — {j.pourquoi_pas}
              </li>
            ))}
          </ul>
        </details>
      )}
    </section>
  )
}

/**
 * Import et export Excel.
 *
 * L'export et l'import sont sur le même écran parce qu'ils forment un aller-retour : on
 * exporte pour corriger dans un tableur, et on réimporte. Les séparer aurait obligé à
 * chercher le second après avoir fait le premier.
 */
export default function EchangesPage() {
  const peutImporter = useAuthStore((s) => s.permissions.includes('importer_donnees'))

  const { data: catalogue, isLoading } = useQuery({
    queryKey: ['echanges-catalogue'],
    queryFn: fetchCatalogueEchanges,
  })

  return (
    <div>
      <header className="mb-6">
        <h1 className="text-2xl font-bold text-heading">Import / Export Excel</h1>
        <p className="mt-1 text-sm font-medium text-muted">
          {catalogue?.etablissement
            ? <>Année <strong>{catalogue?.annee}</strong>, établissement <strong>{catalogue.etablissement}</strong>. </>
            : <>Année <strong>{catalogue?.annee}</strong>, toute la société. </>}
          Un export rend exactement ce que les écrans montrent, ni plus ni moins.
        </p>
      </header>

      {isLoading && <p className="font-medium text-muted">Chargement…</p>}

      {catalogue && (
        <div className="space-y-5">
          <SectionExport catalogue={catalogue} />
          {peutImporter && <SectionImport catalogue={catalogue} />}
        </div>
      )}
    </div>
  )
}
