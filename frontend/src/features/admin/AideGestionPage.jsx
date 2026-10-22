import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CircleHelp, Pencil, Plus, Trash2 } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import Textarea from '../../components/ui/Textarea'
import ModaleFormulaire from '../../components/ui/ModaleFormulaire'
import MessageErreur from '../../components/ui/MessageErreur'
import MessageRefus from '../../components/ui/MessageRefus'
import { GROUPS } from '../../lib/navigation'
import {
  enregistrerArticleAide, enregistrerCategorieAide, fetchAideGestion,
  supprimerArticleAide, supprimerCategorieAide,
} from '../aide/aideApi'
import { ICONES_AIDE, iconeAide } from '../aide/icones'

const CATEGORIE_VIDE = { titre: '', icone: 'BookOpen', ordre: 0, active: true }
const ARTICLE_VIDE = { titre: '', contenu: '', module: '', ordre: 0, active: true }

// Rubriques de l'application auxquelles un article peut se rattacher : les groupes du
// menu, pour ne pas en tenir une seconde liste qui divergerait.
const MODULES = GROUPS.map((g) => g.label)

function PastilleEtat({ active }) {
  return (
    <span
      className="rounded-full px-2 py-0.5 text-xs font-bold"
      style={active
        ? { background: 'var(--info-bg)', color: 'var(--info-text)' }
        : { background: 'var(--surface-2)', color: 'var(--muted-text)' }}
    >
      {active ? 'Actif' : 'Inactif'}
    </span>
  )
}

function CaseActive({ valeur, onChange, libelle }) {
  return (
    <label className="flex items-center gap-2 text-sm font-bold text-heading">
      <input type="checkbox" checked={valeur} onChange={(e) => onChange(e.target.checked)} />
      {libelle}
    </label>
  )
}

/** Erreurs de validation Laravel → { champ: [messages] }, sinon un message général. */
const lireErreurs = (e) => e?.response?.data?.errors ?? { _: [e?.response?.data?.message ?? 'Enregistrement impossible.'] }

/**
 * Console — gestion de l'Aide (Super Admin).
 *
 * Liste TOUT, actif ou non : un article désactivé reste ici, marqué « Inactif », et se
 * réactive en le modifiant. Côté lecteur, il a disparu — c'est le seul effet voulu.
 */
export default function AideGestionPage() {
  const qc = useQueryClient()
  const [categorie, setCategorie] = useState(null)
  const [article, setArticle] = useState(null)
  const [erreurs, setErreurs] = useState({})
  const [refus, setRefus] = useState(null)

  const { data, isLoading, error } = useQuery({ queryKey: ['console-aide'], queryFn: fetchAideGestion, retry: false })

  const rafraichir = () => {
    qc.invalidateQueries({ queryKey: ['console-aide'] })
    qc.invalidateQueries({ queryKey: ['aide'] })
  }
  const fermer = () => { setCategorie(null); setArticle(null); setErreurs({}) }

  const majCategorie = useMutation({
    mutationFn: enregistrerCategorieAide,
    onSuccess: () => { rafraichir(); fermer() },
    onError: (e) => setErreurs(lireErreurs(e)),
  })
  const majArticle = useMutation({
    mutationFn: enregistrerArticleAide,
    onSuccess: () => { rafraichir(); fermer() },
    onError: (e) => setErreurs(lireErreurs(e)),
  })
  const suppression = useMutation({
    mutationFn: ({ type, id }) => (type === 'categorie' ? supprimerCategorieAide(id) : supprimerArticleAide(id)),
    onSuccess: () => { rafraichir(); setRefus(null) },
    onError: (e) => setRefus(e?.response?.data?.message ?? 'Suppression impossible.'),
  })

  const supprimerCategorie = (c) => {
    const n = c.articles.length
    const suite = n > 0 ? ` Ses ${n} article${n > 1 ? 's' : ''} ser${n > 1 ? 'ont' : 'a'} supprimé${n > 1 ? 's' : ''} avec elle.` : ''
    if (window.confirm(`Supprimer la catégorie « ${c.titre} » ?${suite}`)) suppression.mutate({ type: 'categorie', id: c.id })
  }
  const supprimerArticle = (a) => {
    if (window.confirm(`Supprimer l’article « ${a.titre} » ?`)) suppression.mutate({ type: 'article', id: a.id })
  }

  const champCat = (k, v) => setCategorie((f) => ({ ...f, [k]: v }))
  const champArt = (k, v) => setArticle((f) => ({ ...f, [k]: v }))

  return (
    <div>
      <header className="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2.5 text-2xl font-bold text-heading">
            <CircleHelp size={24} strokeWidth={2.5} style={{ color: 'var(--brand-accent)' }} />
            Aide
          </h1>
          <p className="mt-1 max-w-3xl text-sm font-medium text-muted">
            La documentation que lisent les utilisateurs depuis l’application. Un élément
            inactif reste listé ici, mais n’est plus visible des lecteurs.
          </p>
        </div>
        <Button onClick={() => { setErreurs({}); setCategorie({ ...CATEGORIE_VIDE }) }}>
          <Plus size={16} /> Nouvelle catégorie
        </Button>
      </header>

      <MessageRefus message={refus} onFermer={() => setRefus(null)} />

      {isLoading && <p className="font-medium text-muted">Chargement…</p>}
      {!isLoading && !data && <MessageErreur erreur={error} permission="Console générale (Super Admin)" />}
      {data?.length === 0 && (
        <p className="card rounded-2xl px-5 py-8 text-center text-sm font-medium text-muted">
          Aucune catégorie : commencez par en créer une, puis ajoutez-y des articles.
        </p>
      )}

      <div className="grid gap-4 lg:grid-cols-2">
        {data?.map((c) => {
          const Icone = iconeAide(c.icone)
          return (
            <section key={c.id} className="card rounded-2xl p-5" style={c.active ? undefined : { opacity: 0.75 }}>
              <div className="mb-3 flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <h2 className="flex items-center gap-2 text-base font-bold text-heading">
                    <Icone size={18} strokeWidth={2.5} style={{ color: 'var(--brand-accent)' }} />
                    <span className="truncate">{c.titre}</span>
                  </h2>
                  <div className="mt-1 flex items-center gap-2 text-xs text-muted">
                    <PastilleEtat active={c.active} />
                    <span>Ordre {c.ordre}</span>
                  </div>
                </div>
                <div className="flex shrink-0 gap-1">
                  <Button
                    variant="outline" className="!px-2.5 !py-1" title="Modifier la catégorie"
                    onClick={() => { setErreurs({}); setCategorie({ ...c, icone: c.icone ?? '' }) }}
                  >
                    <Pencil size={14} />
                  </Button>
                  <Button
                    variant="outline" className="!px-2.5 !py-1" title="Supprimer la catégorie et ses articles"
                    onClick={() => supprimerCategorie(c)}
                  >
                    <Trash2 size={14} />
                  </Button>
                </div>
              </div>

              {c.articles.length === 0 ? (
                <p className="mb-3 text-sm text-muted">Aucun article dans cette catégorie.</p>
              ) : (
                <ul className="mb-3 divide-y" style={{ borderColor: 'var(--border)' }}>
                  {c.articles.map((a) => (
                    <li key={a.id} className="flex items-center justify-between gap-2 py-2" style={{ borderColor: 'var(--border)' }}>
                      <div className="min-w-0">
                        <div className={`truncate text-sm font-semibold ${a.active ? 'text-heading' : 'text-muted'}`}>{a.titre}</div>
                        <div className="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-muted">
                          <PastilleEtat active={a.active} />
                          {a.module && <span>{a.module}</span>}
                        </div>
                      </div>
                      <div className="flex shrink-0 gap-1">
                        <Button
                          variant="outline" className="!px-2.5 !py-1" title="Modifier l’article"
                          onClick={() => { setErreurs({}); setArticle({ ...a, module: a.module ?? '' }) }}
                        >
                          <Pencil size={14} />
                        </Button>
                        <Button
                          variant="outline" className="!px-2.5 !py-1" title="Supprimer l’article"
                          onClick={() => supprimerArticle(a)}
                        >
                          <Trash2 size={14} />
                        </Button>
                      </div>
                    </li>
                  ))}
                </ul>
              )}

              <Button
                variant="outline" className="!px-3 !py-1"
                onClick={() => { setErreurs({}); setArticle({ ...ARTICLE_VIDE, categorie_id: c.id }) }}
              >
                <Plus size={14} /> Ajouter un article
              </Button>
            </section>
          )
        })}
      </div>

      {categorie && (
        <ModaleFormulaire
          titre={categorie.id ? `Modifier « ${categorie.titre} »` : 'Nouvelle catégorie'}
          onFermer={fermer}
          onValider={() => majCategorie.mutate({ ...categorie, icone: categorie.icone || null, ordre: Number(categorie.ordre) || 0 })}
          enCours={majCategorie.isPending}
          valideDesactive={!categorie.titre.trim()}
        >
          <Input id="cat-titre" label="Titre" maxLength={200} value={categorie.titre}
                 error={erreurs.titre?.[0]} onChange={(e) => champCat('titre', e.target.value)} />
          <Select id="cat-icone" label="Icône" value={categorie.icone ?? ''}
                  error={erreurs.icone?.[0]} onChange={(e) => champCat('icone', e.target.value)}>
            <option value="">Par défaut</option>
            {Object.keys(ICONES_AIDE).map((nom) => <option key={nom} value={nom}>{nom}</option>)}
          </Select>
          <Input id="cat-ordre" label="Ordre d’affichage" type="number" min={0} value={categorie.ordre}
                 error={erreurs.ordre?.[0]} onChange={(e) => champCat('ordre', e.target.value)} />
          <CaseActive libelle="Visible des lecteurs" valeur={categorie.active} onChange={(v) => champCat('active', v)} />
          {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}
        </ModaleFormulaire>
      )}

      {article && (
        <ModaleFormulaire
          large
          titre={article.id ? `Modifier « ${article.titre} »` : 'Nouvel article'}
          onFermer={fermer}
          onValider={() => majArticle.mutate({ ...article, module: article.module || null, ordre: Number(article.ordre) || 0 })}
          enCours={majArticle.isPending}
          valideDesactive={!article.titre.trim() || !article.contenu.trim()}
        >
          <Select id="art-cat" label="Catégorie" value={article.categorie_id}
                  error={erreurs.categorie_id?.[0]} onChange={(e) => champArt('categorie_id', Number(e.target.value))}>
            {data?.map((c) => <option key={c.id} value={c.id}>{c.titre}</option>)}
          </Select>
          <Input id="art-titre" label="Titre" maxLength={200} value={article.titre}
                 error={erreurs.titre?.[0]} onChange={(e) => champArt('titre', e.target.value)} />
          <Textarea id="art-contenu" label="Contenu" rows={10} value={article.contenu}
                    placeholder="Texte simple : les retours à la ligne sont conservés à l’affichage."
                    error={erreurs.contenu?.[0]} onChange={(e) => champArt('contenu', e.target.value)} />
          <div className="grid gap-4 sm:grid-cols-2">
            <Select id="art-module" label="Rubrique concernée" value={article.module}
                    error={erreurs.module?.[0]} onChange={(e) => champArt('module', e.target.value)}>
              <option value="">Aucune</option>
              {/* Une rubrique renommée depuis reste proposée, pour ne pas l'effacer en silence. */}
              {article.module && !MODULES.includes(article.module) && <option value={article.module}>{article.module}</option>}
              {MODULES.map((m) => <option key={m} value={m}>{m}</option>)}
            </Select>
            <Input id="art-ordre" label="Ordre d’affichage" type="number" min={0} value={article.ordre}
                   error={erreurs.ordre?.[0]} onChange={(e) => champArt('ordre', e.target.value)} />
          </div>
          <CaseActive libelle="Visible des lecteurs" valeur={article.active} onChange={(v) => champArt('active', v)} />
          {erreurs._ && <p className="text-sm text-red-600">{erreurs._[0]}</p>}
        </ModaleFormulaire>
      )}
    </div>
  )
}
